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

/// Length of a `device_fingerprint`, in lowercase hex characters.
///
/// `backend/app/Http/Requests/Auth/DeviceTokenRequest.php` validates
/// `['required','string','size:64','regex:/^[0-9a-f]{64}$/']` — 256 bits, sha256-shaped. It is
/// not decoration: the fingerprint is the KEY of the one-token-per-device rule, so the server
/// pins the shape to something a guesser cannot walk.
const FINGERPRINT_HEX_LEN: usize = 64;

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
///
/// `LogoutOutcome::WipedLocalOnly(reason)` means the local wipe happened but the server token
/// survived (the normal offline case). The webview should treat it as a successful logout and
/// surface the reason — the recourse is the Devices page.
#[tauri::command]
pub async fn logout(state: State<'_, AppState>, force: bool) -> CommandResult<LogoutOutcome> {
    let engine = state.engine.clone();
    engine.logout(force).await.map_err(CommandError::from)
}

/// The session the engine currently holds, plus the bearer token behind it.
///
/// Wire shape: `{"session": Session | null, "token": string | null}`.
#[derive(Debug, Clone, Serialize)]
pub struct SessionSnapshot {
    /// The cached session document, or `null` when signed out.
    pub session: Option<Session>,
    /// The device bearer token, or `null` when there is none. Present only alongside a
    /// session — a token with no session is treated as "signed out".
    pub token: Option<String>,
}

/// `auth::session` — the session and bearer token **without touching the network**.
///
/// Two AUTH-1 findings share this one command:
///
/// * **U2.** No command ever handed the device token to the webview, so `platform/http.ts`'s
///   `setDeviceToken` had nothing to be called with, and every request the local mirror cannot
///   answer — the §8 online-only endpoints, `/api/broadcasting/auth`, `/api/password/change` —
///   went out unauthenticated and came back 401.
/// * **U5.** `auth::restore` goes through `SyncEngine::restore_session()`, which forces a
///   manifest round-trip, so it cannot answer while offline. The engine has had the session in
///   memory since `open()` (it reads it back from `desktop_settings`); it simply had no way
///   out. This command is that way out, so the webview no longer needs its own plaintext copy
///   of the identity document in `localStorage`.
///
/// Handing the token to the webview does not weaken K9: the token is stored **only** in the OS
/// keychain and is already sent on the wire by the engine on every request. What must not
/// happen is it being written to disk or a log — `logging.rs` (§9/9) masks the log side.
#[tauri::command]
pub fn session(state: State<'_, AppState>) -> CommandResult<SessionSnapshot> {
    let Some(session) = state.engine.session() else {
        return Ok(SessionSnapshot {
            session: None,
            token: None,
        });
    };
    let token = SystemKeyStore
        .get(&state.keychain_service, KEY_TOKEN)
        .map_err(CommandError::from)?;
    Ok(SessionSnapshot {
        session: Some(session),
        token,
    })
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

/// The envelope `GET /api/me/devices` actually answers with.
///
/// `DeviceController::index` returns `response()->json(['data' => $devices])`, i.e. the rows
/// are one level down. Deserialising straight into `Vec<DeviceSummary>` — which is what this
/// command used to do — failed on every response, and the serde error surfaced as a generic
/// `HTTP_ERROR`, so the Devices screen never opened at all (AUTH-1 U4).
#[derive(Debug, Clone, Deserialize)]
struct DeviceListEnvelope {
    data: Vec<DeviceSummary>,
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
    Ok(response
        .json::<DeviceListEnvelope>()
        .await
        .map_err(http_error)?
        .data)
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
        .ok_or_else(|| CommandError::new("AUTH_REQUIRED", "no device token in the keychain"))
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
///
/// # Why random-and-stored rather than derived from machine identity
///
/// Both shapes satisfy "same value on the same device". The choice went to random because of
/// what the *derived* shape would put on the server: `personal_access_tokens.device_fingerprint`
/// is a stored column, and deriving it from hostname + OS user name would make every row a
/// (hashed, but stable and enumerable) statement about the customer's machine names and
/// account names. `SYNCDESKTOP.md` §9 has no reason to carry that, and a device identifier does
/// not need to *mean* anything — it only needs to be unique and stable.
///
/// Stability comes from the OS credential store, which is the same durability guarantee K9
/// already leans on for the token and the SQLCipher key: it survives app reinstalls and
/// database wipes, and is scoped to this OS user. Losing it (a new OS profile, a cleared
/// credential store) mints a new fingerprint and leaves one stale row on the Devices page,
/// which the user can revoke — strictly better than a derived value colliding across two
/// machines that a corporate image gave the same hostname and user name, where the second
/// login would silently revoke the first machine's token.
///
/// A value that does not match the server's shape is **replaced**, not returned: builds before
/// the AUTH-1 wire audit stored a dashed `Uuid::new_v4()` here (36 characters), which the
/// server rejects with `422 VALIDATION_ERROR` — the desktop client could not log in at all
/// (U1). Repairing it here is what makes existing installs recover on their own.
fn device_fingerprint(keychain_service: &str) -> CommandResult<String> {
    let store = SystemKeyStore;
    if let Some(existing) = store
        .get(keychain_service, DEVICE_FINGERPRINT_KEY)
        .map_err(CommandError::from)?
    {
        if is_valid_fingerprint(&existing) {
            return Ok(existing);
        }
    }
    let generated = random_fingerprint();
    store
        .set(keychain_service, DEVICE_FINGERPRINT_KEY, &generated)
        .map_err(CommandError::from)?;
    Ok(generated)
}

/// Exactly what `DeviceTokenRequest`'s `size:64` + `/^[0-9a-f]{64}$/` accepts.
fn is_valid_fingerprint(value: &str) -> bool {
    value.len() == FINGERPRINT_HEX_LEN
        && value
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}

/// 256 bits from the platform CSPRNG, rendered as lowercase hex.
///
/// Two v4 UUIDs concatenated: `uuid` sources them from `getrandom`, the same entropy a
/// dedicated `rand` (or a `sha2` digest of one) would reach for. This is deliberately the same
/// construction `syncra_sync::keystore::random_hex_key` uses for the SQLCipher key, for the
/// same reason — keeping the dependency list to the specified set matters for `cargo audit`.
fn random_fingerprint() -> String {
    let mut out = String::with_capacity(FINGERPRINT_HEX_LEN);
    let a = Uuid::new_v4();
    let b = Uuid::new_v4();
    for byte in a.as_bytes().iter().chain(b.as_bytes().iter()) {
        use std::fmt::Write as _;
        let _ = write!(out, "{byte:02x}");
    }
    out
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
    Err(CommandError::new(
        format!("HTTP_{}", status.as_u16()),
        if body.is_empty() {
            status.to_string()
        } else {
            body
        },
    ))
}

fn http_error(err: reqwest::Error) -> CommandError {
    CommandError::new("HTTP_ERROR", err.to_string())
}

fn validation_error(message: String) -> CommandError {
    CommandError::new("VALIDATION_ERROR", message)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// U1: `DeviceTokenRequest` validates `size:64` + `/^[0-9a-f]{64}$/`. The old generator
    /// produced a dashed `Uuid::new_v4()` — 36 characters — so `POST /api/auth/device`
    /// answered 422 and the desktop client could not log in under any circumstances.
    #[test]
    fn a_generated_fingerprint_matches_the_servers_rule() {
        let fingerprint = random_fingerprint();
        assert_eq!(fingerprint.len(), FINGERPRINT_HEX_LEN);
        assert!(
            fingerprint
                .bytes()
                .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b)),
            "{fingerprint}"
        );
        assert!(is_valid_fingerprint(&fingerprint));
    }

    #[test]
    fn two_fingerprints_are_not_the_same_value() {
        assert_ne!(random_fingerprint(), random_fingerprint());
    }

    /// The shapes that must be REPLACED rather than reused, so an install carrying a value
    /// from an older build repairs itself on the next login instead of failing forever.
    #[test]
    fn the_old_uuid_shape_is_rejected() {
        assert!(
            !is_valid_fingerprint(&Uuid::new_v4().to_string()),
            "a dashed uuid is what U1 was"
        );
        assert!(!is_valid_fingerprint(""));
        assert!(!is_valid_fingerprint(&"a".repeat(63)));
        assert!(!is_valid_fingerprint(&"a".repeat(65)));
        // Uppercase hex fails the server's `[0-9a-f]` class.
        assert!(!is_valid_fingerprint(&"A".repeat(64)));
        // Non-hex letters inside a 64-character string.
        assert!(!is_valid_fingerprint(&"z".repeat(64)));
    }

    /// U4: the rows arrive under `data`. Deserialising the bare array failed on every
    /// response and the serde error surfaced as `HTTP_ERROR`, so the Devices screen never
    /// opened.
    #[test]
    fn the_device_list_envelope_is_unwrapped() {
        let body = serde_json::json!({
            "data": [{
                "id": 5,
                "name": "AYBERK-PC",
                "platform": "windows",
                "last_used_at": "2026-08-31T09:00:00+00:00",
                "created_at": "2026-08-30T09:00:00+00:00",
                "is_current": true
            }]
        });
        let parsed: DeviceListEnvelope = serde_json::from_value(body).expect("envelope");
        assert_eq!(parsed.data.len(), 1);
        assert_eq!(parsed.data[0].id, 5);
        assert!(parsed.data[0].is_current);

        // The bare array is NOT what the server sends; proving that keeps the fix honest.
        let bare = serde_json::json!([]);
        assert!(serde_json::from_value::<DeviceListEnvelope>(bare).is_err());
    }

    /// `platform` and `last_used_at` are nullable on the server side.
    #[test]
    fn a_device_row_tolerates_missing_optional_fields() {
        let body = serde_json::json!({
            "data": [{ "id": 1, "name": "x", "created_at": "2026-08-30T09:00:00+00:00", "is_current": false }]
        });
        let parsed: DeviceListEnvelope = serde_json::from_value(body).expect("envelope");
        assert!(parsed.data[0].platform.is_none());
        assert!(parsed.data[0].last_used_at.is_none());
    }
}
