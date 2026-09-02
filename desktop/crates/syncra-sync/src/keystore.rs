//! Secret storage.
//!
//! `SYNCDESKTOP.md` K9 is categorical: the device token and the SQLCipher key live in the
//! OS keychain, never in a plain file. [`SystemKeyStore`] is that implementation.
//!
//! [`MemoryKeyStore`] exists so that tests — and any embedder that wants to supply its own
//! vault — can drive the engine without touching the user's real credential store. It is
//! never selected by [`crate::SyncEngine::open`]; you have to ask for it explicitly through
//! [`crate::SyncEngine::open_with_keystore`].

use crate::error::{Result, SyncError};
use std::collections::HashMap;
use std::sync::{Arc, Mutex};

/// Keychain entry holding the device bearer token.
pub const KEY_TOKEN: &str = "device-token";
/// Keychain entry holding the SQLCipher database key (64 lowercase hex characters).
pub const KEY_DB: &str = "db-key";

/// A place to keep secrets.
pub trait KeyStore: Send + Sync + std::fmt::Debug {
    /// Read a secret; `None` when the entry does not exist.
    fn get(&self, service: &str, key: &str) -> Result<Option<String>>;
    /// Write (or overwrite) a secret.
    fn set(&self, service: &str, key: &str, value: &str) -> Result<()>;
    /// Remove a secret; removing a missing entry is not an error.
    fn delete(&self, service: &str, key: &str) -> Result<()>;
}

/// Shared handle to a keystore.
pub type KeyStoreHandle = Arc<dyn KeyStore>;

/// The OS keychain: Windows Credential Manager, macOS Keychain, Secret Service on Linux.
#[derive(Debug, Default)]
pub struct SystemKeyStore;

impl KeyStore for SystemKeyStore {
    fn get(&self, service: &str, key: &str) -> Result<Option<String>> {
        let entry = keyring::Entry::new(service, key)
            .map_err(|e| SyncError::Validation(format!("keychain: {e}")))?;
        match entry.get_password() {
            Ok(v) => Ok(Some(v)),
            Err(keyring::Error::NoEntry) => Ok(None),
            Err(e) => Err(SyncError::Validation(format!("keychain read: {e}"))),
        }
    }

    fn set(&self, service: &str, key: &str, value: &str) -> Result<()> {
        let entry = keyring::Entry::new(service, key)
            .map_err(|e| SyncError::Validation(format!("keychain: {e}")))?;
        entry
            .set_password(value)
            .map_err(|e| SyncError::Validation(format!("keychain write: {e}")))
    }

    fn delete(&self, service: &str, key: &str) -> Result<()> {
        let entry = keyring::Entry::new(service, key)
            .map_err(|e| SyncError::Validation(format!("keychain: {e}")))?;
        match entry.delete_credential() {
            Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
            Err(e) => Err(SyncError::Validation(format!("keychain delete: {e}"))),
        }
    }
}

/// In-process keystore for tests and embedders that manage their own vault.
#[derive(Debug, Default)]
pub struct MemoryKeyStore {
    inner: Mutex<HashMap<(String, String), String>>,
}

impl MemoryKeyStore {
    /// An empty store.
    pub fn new() -> Self {
        Self::default()
    }
}

impl KeyStore for MemoryKeyStore {
    fn get(&self, service: &str, key: &str) -> Result<Option<String>> {
        let guard = self.inner.lock().expect("keystore mutex");
        Ok(guard.get(&(service.to_string(), key.to_string())).cloned())
    }

    fn set(&self, service: &str, key: &str, value: &str) -> Result<()> {
        let mut guard = self.inner.lock().expect("keystore mutex");
        guard.insert((service.to_string(), key.to_string()), value.to_string());
        Ok(())
    }

    fn delete(&self, service: &str, key: &str) -> Result<()> {
        let mut guard = self.inner.lock().expect("keystore mutex");
        guard.remove(&(service.to_string(), key.to_string()));
        Ok(())
    }
}

/// Fetch the SQLCipher key for `service`, generating and storing one on first use.
pub(crate) fn ensure_db_key(store: &dyn KeyStore, service: &str) -> Result<String> {
    if let Some(existing) = store.get(service, KEY_DB)? {
        if existing.len() == 64 && existing.chars().all(|c| c.is_ascii_hexdigit()) {
            return Ok(existing);
        }
    }
    let key = random_hex_key();
    store.set(service, KEY_DB, &key)?;
    Ok(key)
}

/// 256 bits of key material rendered as lowercase hex.
///
/// Two v4-shaped UUIDs are concatenated; `uuid` sources them from the platform CSPRNG
/// (`getrandom`), which is the same entropy source a dedicated `rand` dependency would
/// reach for. Keeping the dependency list to the specified set matters for `cargo audit`.
fn random_hex_key() -> String {
    let a = uuid::Uuid::new_v4();
    let b = uuid::Uuid::new_v4();
    let mut out = String::with_capacity(64);
    for byte in a.as_bytes().iter().chain(b.as_bytes().iter()) {
        use std::fmt::Write as _;
        let _ = write!(out, "{byte:02x}");
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn generated_key_is_64_hex_chars() {
        let key = random_hex_key();
        assert_eq!(key.len(), 64);
        assert!(key.chars().all(|c| c.is_ascii_hexdigit()));
    }

    #[test]
    fn ensure_db_key_is_stable_across_calls() {
        let store = MemoryKeyStore::new();
        let first = ensure_db_key(&store, "svc").unwrap();
        let second = ensure_db_key(&store, "svc").unwrap();
        assert_eq!(first, second);
    }

    #[test]
    fn memory_store_round_trips() {
        let store = MemoryKeyStore::new();
        assert_eq!(store.get("svc", "k").unwrap(), None);
        store.set("svc", "k", "v").unwrap();
        assert_eq!(store.get("svc", "k").unwrap().as_deref(), Some("v"));
        store.delete("svc", "k").unwrap();
        assert_eq!(store.get("svc", "k").unwrap(), None);
        // Deleting a missing entry is not an error.
        store.delete("svc", "k").unwrap();
    }
}
