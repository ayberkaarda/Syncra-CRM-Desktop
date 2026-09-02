//! HTTP client for the four endpoints the desktop client speaks to.
//!
//! The transport does one thing beyond serialisation: it separates "the server said no"
//! from "the network is not there". A connect/timeout failure becomes
//! [`SyncError::Offline`] so the sync loop can back off; a 401 becomes [`SyncError::Auth`]
//! so the engine can raise `AuthLost` while keeping the outbox (`SYNCDESKTOP.md` §5.5). A
//! `5xx` becomes [`SyncError::Server`] — the sync loop still treats it as transient and
//! retries, but the UI no longer reports a live server's own `500` as "no internet
//! connection" (O25).

use crate::error::{Result, ServerError, SyncError};
use crate::protocol::{
    codes, ApiErrorBody, DeviceLoginRequest, DeviceLoginResponse, Manifest, PullRequest,
    PullResponse, PushRequest, PushResponse,
};
use crate::types::DeviceInfo;
use reqwest::{Client, StatusCode};
use std::time::Duration;
use url::Url;

/// Request timeout for every call.
const REQUEST_TIMEOUT: Duration = Duration::from_secs(60);

/// Thin wrapper over `reqwest` that knows the four sync endpoints.
#[derive(Debug, Clone)]
pub struct Transport {
    client: Client,
    api_base: Url,
}

impl Transport {
    /// Build a transport against `api_base` (which should end in `/api/`).
    pub fn new(api_base: Url) -> Result<Self> {
        let client = Client::builder()
            .timeout(REQUEST_TIMEOUT)
            .user_agent(concat!("syncra-desktop/", env!("CARGO_PKG_VERSION")))
            .build()?;
        Ok(Transport { client, api_base })
    }

    fn url(&self, path: &str) -> Result<Url> {
        self.api_base
            .join(path)
            .map_err(|e| SyncError::Validation(format!("bad api_base for {path}: {e}")))
    }

    /// `POST /api/auth/device` (§4.3).
    pub async fn device_login(
        &self,
        email: &str,
        password: &str,
        device: &DeviceInfo,
    ) -> Result<DeviceLoginResponse> {
        let body = DeviceLoginRequest {
            email,
            password,
            device_name: &device.device_name,
            device_fingerprint: &device.device_fingerprint,
            platform: &device.platform,
            app_version: &device.app_version,
        };
        let response = self
            .client
            .post(self.url("auth/device")?)
            .json(&body)
            .send()
            .await
            .map_err(classify)?;

        let status = response.status();
        if status.is_success() {
            return Ok(response.json::<DeviceLoginResponse>().await?);
        }

        // Every refusal — 401 `INVALID_CREDENTIALS`, 403 `USER_INACTIVE`, 423 `LOCKED_OUT` with
        // its `retry_after`, and the 422 that U1 produced — becomes one structured error. It
        // used to be `SyncError::Validation(<code>)`, i.e. the code lived only in a message
        // string and `retry_after` was dropped, and 422 fell through to `Protocol` (U11/U12).
        Err(SyncError::Server(server_error(status, response).await))
    }

    /// `DELETE /api/me/devices/{token_id}` (`SYNCDESKTOP.md` §4.3).
    ///
    /// Not a sync endpoint, but [`crate::SyncEngine::logout`] needs it: deleting the keychain
    /// entry only makes the client forget the token — the server row stayed usable, and a live
    /// check after a desktop logout found the personal access token still alive with a fresh
    /// `last_used_at` (AUTH-1 U6). `404` counts as success: the token is gone either way.
    pub async fn revoke_device(&self, token: &str, token_id: i64) -> Result<()> {
        let response = self
            .client
            .delete(self.url(&format!("me/devices/{token_id}"))?)
            .bearer_auth(token)
            .send()
            .await
            .map_err(classify)?;

        let status = response.status();
        if status.is_success() || status == StatusCode::NOT_FOUND {
            return Ok(());
        }
        if status == StatusCode::UNAUTHORIZED {
            // The token was already invalid; there is nothing left to revoke.
            return Err(SyncError::Auth);
        }
        Err(SyncError::Server(server_error(status, response).await))
    }

    /// `GET /api/sync/manifest` (§4.1).
    pub async fn manifest(&self, token: &str) -> Result<Manifest> {
        let response = self
            .client
            .get(self.url("sync/manifest")?)
            .bearer_auth(token)
            .send()
            .await
            .map_err(classify)?;
        self.decode(response, "sync/manifest").await
    }

    /// `POST /api/sync/pull` (§4.2).
    pub async fn pull(&self, token: &str, request: &PullRequest) -> Result<PullResponse> {
        let response = self
            .client
            .post(self.url("sync/pull")?)
            .bearer_auth(token)
            .json(request)
            .send()
            .await
            .map_err(classify)?;
        self.decode(response, "sync/pull").await
    }

    /// `POST /api/sync/push` (§4.3).
    pub async fn push(&self, token: &str, request: &PushRequest) -> Result<PushResponse> {
        let response = self
            .client
            .post(self.url("sync/push")?)
            .bearer_auth(token)
            .json(request)
            .send()
            .await
            .map_err(classify)?;
        self.decode(response, "sync/push").await
    }

    async fn decode<T: serde::de::DeserializeOwned>(
        &self,
        response: reqwest::Response,
        what: &str,
    ) -> Result<T> {
        let status = response.status();
        if status == StatusCode::UNAUTHORIZED {
            // A bare 401 stays `Auth`: KARAR A25 keeps the outbox for it. Only the explicit
            // 403 below is allowed to mean "this account is gone".
            return Err(SyncError::Auth);
        }
        if status.is_server_error() {
            // The server answered — it just answered badly. This used to collapse into
            // `Offline`, which meant a real `500 SQLSTATE[...]` looked exactly like a dead
            // network to the user and nobody looked at a server log (O25). It is still
            // transient — `sync/mod.rs`'s loop backs off and retries a `Server` with
            // `status >= 500` the same way it always retried `Offline` — but the caller now
            // gets the truth.
            return Err(SyncError::Server(server_error(status, response).await));
        }
        if !status.is_success() {
            // 403 carries the codes that decide behaviour: `USER_DEACTIVATED` (KARAR A25 —
            // wipe), `ABILITY_REQUIRED`, `PASSWORD_CHANGE_REQUIRED`. Folding it into
            // `SyncError::Protocol` — which is what happened before — made all three the same
            // unactionable "protocol error" (AUTH-1 U16 / open item O1).
            return Err(SyncError::Server(server_error(status, response).await));
        }
        let text = response.text().await?;
        serde_json::from_str::<T>(&text)
            .map_err(|e| SyncError::Protocol(format!("{what} body: {e}")))
    }
}

/// Turn a non-2xx response into a [`ServerError`], whatever shape the body came in.
///
/// The code falls back to `VALIDATION_ERROR` for a 422 (Laravel sends a field map and no code
/// there) and to `HTTP_<status>` otherwise, so `SyncError::code()` is never empty and the UI
/// always has something to map through `desktop.errors.*`.
async fn server_error(status: StatusCode, response: reqwest::Response) -> ServerError {
    let text = response.text().await.unwrap_or_default();
    let body = serde_json::from_str::<serde_json::Value>(&text)
        .map(|value| ApiErrorBody::from_value(&value))
        .unwrap_or_default();

    let code = body.code.unwrap_or_else(|| {
        if status == StatusCode::UNPROCESSABLE_ENTITY {
            codes::VALIDATION_ERROR.to_string()
        } else {
            format!("HTTP_{}", status.as_u16())
        }
    });

    ServerError {
        status: status.as_u16(),
        code,
        message: body.message.or_else(|| {
            if text.is_empty() {
                None
            } else {
                Some(truncate(&text, 512))
            }
        }),
        retry_after: body.retry_after,
    }
}

/// Network-level failures are "offline"; anything else stays an HTTP error.
fn classify(err: reqwest::Error) -> SyncError {
    if err.is_timeout() || err.is_connect() || err.is_request() {
        SyncError::Offline
    } else {
        SyncError::Http(err)
    }
}

fn truncate(s: &str, max: usize) -> String {
    if s.len() <= max {
        s.to_string()
    } else {
        let mut end = max;
        while end > 0 && !s.is_char_boundary(end) {
            end -= 1;
        }
        format!("{}…", &s[..end])
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn urls_are_joined_under_the_api_base() {
        let t = Transport::new(Url::parse("https://crm.example.com/api/").unwrap()).unwrap();
        assert_eq!(
            t.url("sync/pull").unwrap().as_str(),
            "https://crm.example.com/api/sync/pull"
        );
    }

    #[test]
    fn truncate_respects_char_boundaries() {
        let s = "şşşşş";
        let out = truncate(s, 3);
        assert!(out.ends_with('…'));
    }
}
