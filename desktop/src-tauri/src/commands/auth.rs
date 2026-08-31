//! `auth::*` — session lifecycle and device management (`SYNCDESKTOP.md` §6.2).
//!
//! `login`, `restore` and `logout` delegate straight to [`syncra_sync::SyncEngine`].
//! `list_devices` and `revoke_device` do **not** — `docs/DESKTOP-ARCHITECTURE.md` §5.2 maps
//! them to `GET /api/me/devices` and `DELETE /api/me/devices/{token}` directly, which are not
//! part of the engine's frozen API (`docs/DESKTOP-SYNC-PROTOCOL.md` §5). They read the bearer
//! token straight out of the OS keychain via the crate's public `keystore` module.

use serde::{Deserialize, Serialize};
use tauri::State;
use uuid::Uuid;

use syncra_sync::keystore::{KeyStore, SystemKeyStore, KEY_TOKEN};
use syncra_sync::{DeviceInfo, LogoutOutcome, Session};

use super::{CommandError, CommandResult};
use crate::state::AppState;

/// Keychain entry holding the stable per-device fingerprint sent with `POST /api/auth/device`
/// (`SYNCDESKTOP.md` §4.3: "aynı `device_fingerprint` için eski token silinir"). Generated
/// once per install and reused — no `syncra-sync` type owns this, it is pure device identity
/// bookkeeping the shell needs before it can call `SyncEngine::login`.
const DEVICE_FINGERPRINT_KEY: &str = "device-fingerprint";

/// Exchange credentials for a device token.
#[tauri::command]
pub async fn login(state: State<'_, AppState>, email: String, password: String) -> CommandResult<Session> {
    let engine = state.engine.clone();
    let device = device_info(&state.keychain_service)?;
    engine
        .login(&email, &password, device)
        .await
        .map_err(CommandError::from)
}

/// Resume a stored session from the keychain token.
#[tauri::command]
pub async fn restore(state: State<'_, AppState>) -> CommandResult<Option<Session>> {
    let engine = state.engine.clone();
    engine.restore_session().await.map_err(CommandError::from)
}

/// Drop the session. Refuses (`LogoutOutcome::PendingMutations`) while unpushed mutations
/// exist unless `force` is set.
#[tauri::command]
pub async fn logout(state: State<'_, AppState>, force: bool) -> CommandResult<LogoutOutcome> {
    let engine = state.engine.clone();
    engine.logout(force).await.map_err(CommandError::from)
}

/// One row of `GET /api/me/devices` (`SYNCDESKTOP.md` §4.3). Not a `syncra-sync` type: this
/// endpoint is account management, not sync/auth-session state, so the crate does not model
/// it (`docs/DESKTOP-ARCHITECTURE.md` §5.2).
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DeviceSummary {
    /// Personal access token id — what `revoke_device` takes.
    pub id: i64,
    /// `device_name` at login time.
    pub name: String,
    /// `windows` | `macos` | `linux`, when the server has it on file.
    #[serde(default)]
    pub platform: Option<String>,
    #[serde(default)]
    pub last_used_at: Option<String>,
    pub created_at: String,
    /// Whether this row is the token the caller is authenticated with right now.
    pub is_current: bool,
}

/// `GET /api/me/devices` — the user's device list, for the Devices settings page.
#[tauri::command]
pub async fn list_devices(state: State<'_, AppState>) -> CommandResult<Vec<DeviceSummary>> {
    let http = state.http.clone();
    let url = state
        .api_base
        .join("me/devices")
        .map_err(|e| validation_error(format!("bad api_base: {e}")))?;
    let token = bearer_token(&state.keychain_service)?;

    let response = http
        .get(url)
        .bearer_auth(token)
        .send()
        .await
        .map_err(http_error)?;
    let response = ensure_success(response).await?;
    response.json::<Vec<DeviceSummary>>().await.map_err(http_error)
}

/// `DELETE /api/me/devices/{token}` — revoke one device's token. `SYNCDESKTOP.md` §4.3:
/// "yalnızca kendi token'ı; 404 aksi halde."
#[tauri::command]
pub async fn revoke_device(state: State<'_, AppState>, token_id: i64) -> CommandResult<()> {
    let http = state.http.clone();
    let url = state
        .api_base
        .join(&format!("me/devices/{token_id}"))
        .map_err(|e| validation_error(format!("bad api_base: {e}")))?;
    let token = bearer_token(&state.keychain_service)?;

    let response = http
        .delete(url)
        .bearer_auth(token)
        .send()
        .await
        .map_err(http_error)?;
    ensure_success(response).await?;
    Ok(())
}

fn bearer_token(keychain_service: &str) -> CommandResult<String> {
    SystemKeyStore
        .get(keychain_service, KEY_TOKEN)
        .map_err(CommandError::from)?
        .ok_or_else(|| CommandError {
            code: "AUTH_REQUIRED".to_string(),
            message: "no device token in the keychain".to_string(),
        })
}

fn device_info(keychain_service: &str) -> CommandResult<DeviceInfo> {
    Ok(DeviceInfo {
        device_name: device_name(),
        device_fingerprint: device_fingerprint(keychain_service)?,
        platform: platform_name().to_string(),
        app_version: env!("CARGO_PKG_VERSION").to_string(),
    })
}

/// Stable per-device id (`SYNCDESKTOP.md` §4.3 `device_fingerprint`), generated once and
/// cached in the OS keychain next to the device token — same service, its own key.
fn device_fingerprint(keychain_service: &str) -> CommandResult<String> {
    let store = SystemKeyStore;
    if let Some(existing) = store
        .get(keychain_service, DEVICE_FINGERPRINT_KEY)
        .map_err(CommandError::from)?
    {
        return Ok(existing);
    }
    let generated = Uuid::new_v4().to_string();
    store
        .set(keychain_service, DEVICE_FINGERPRINT_KEY, &generated)
        .map_err(CommandError::from)?;
    Ok(generated)
}

/// A human-readable device name for the Devices page. Deliberately not a plugin: `tauri-plugin-os`
/// exposes `hostname()` as a JS-invokable command, not a plain Rust function, and pulling in
/// `tauri-plugin-os`'s scope just for this would be backwards — this is the same hostname a
/// plugin call would surface.
fn device_name() -> String {
    std::env::var("COMPUTERNAME")
        .or_else(|_| std::env::var("HOSTNAME"))
        .unwrap_or_else(|_| "syncra-desktop".to_string())
}

/// `windows` | `macos` | `linux` (`SYNCDESKTOP.md` §4.3) — `std::env::consts::OS` already
/// speaks this exact vocabulary on every platform this shell targets (K11).
fn platform_name() -> &'static str {
    match std::env::consts::OS {
        "macos" => "macos",
        "linux" => "linux",
        _ => "windows",
    }
}

async fn ensure_success(response: reqwest::Response) -> CommandResult<reqwest::Response> {
    if response.status().is_success() {
        return Ok(response);
    }
    let status = response.status();
    let body = response.text().await.unwrap_or_default();
    Err(CommandError {
        code: format!("HTTP_{}", status.as_u16()),
        message: if body.is_empty() {
            status.to_string()
        } else {
            body
        },
    })
}

fn http_error(err: reqwest::Error) -> CommandError {
    CommandError {
        code: "HTTP_ERROR".to_string(),
        message: err.to_string(),
    }
}

fn validation_error(message: String) -> CommandError {
    CommandError {
        code: "VALIDATION_ERROR".to_string(),
        message,
    }
}
