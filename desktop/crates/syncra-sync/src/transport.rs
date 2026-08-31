//! HTTP client for the four endpoints the desktop client speaks to.
//!
//! The transport does one thing beyond serialisation: it separates "the server said no"
//! from "the network is not there". A connect/timeout failure becomes
//! [`SyncError::Offline`] so the sync loop can back off; a 401 becomes [`SyncError::Auth`]
//! so the engine can raise `AuthLost` while keeping the outbox (`SYNCDESKTOP.md` §5.5).

use crate::error::{Result, SyncError};
use crate::protocol::{
    ApiErrorBody, DeviceLoginRequest, DeviceLoginResponse, Manifest, PullRequest, PullResponse,
    PushRequest, PushResponse,
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

        let err: ApiErrorBody = response.json().await.unwrap_or(ApiErrorBody {
            code: None,
            message: None,
            retry_after: None,
        });
        match status {
            StatusCode::UNAUTHORIZED | StatusCode::FORBIDDEN | StatusCode::LOCKED => {
                Err(SyncError::Validation(
                    err.code.unwrap_or_else(|| status.as_str().to_string()),
                ))
            }
            _ => Err(SyncError::Protocol(format!(
                "auth/device returned {status}: {:?}",
                err.code
            ))),
        }
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
            return Err(SyncError::Auth);
        }
        if status == StatusCode::FORBIDDEN {
            // `ability:desktop` / `EnsureDeviceToken` refusals (protocol §3.3, §3.4).
            let body: ApiErrorBody = response.json().await.unwrap_or(ApiErrorBody {
                code: None,
                message: None,
                retry_after: None,
            });
            return Err(SyncError::Protocol(format!(
                "{what} forbidden: {}",
                body.code.unwrap_or_else(|| "FORBIDDEN".into())
            )));
        }
        if status.is_server_error() {
            // Transient: the sync loop backs off and retries.
            return Err(SyncError::Offline);
        }
        if !status.is_success() {
            let text = response.text().await.unwrap_or_default();
            return Err(SyncError::Protocol(format!(
                "{what} returned {status}: {}",
                truncate(&text, 512)
            )));
        }
        let text = response.text().await?;
        serde_json::from_str::<T>(&text)
            .map_err(|e| SyncError::Protocol(format!("{what} body: {e}")))
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
