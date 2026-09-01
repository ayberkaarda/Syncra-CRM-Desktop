//! Updater signature verification — the measurable half (`SYNCDESKTOP.md` §9 item 8).
//!
//! §9 item 8 asks for two things: *"updater imza doğrulaması; imzasız manifest reddi"*. This
//! module holds no production code; it exists so the parts of that claim which **can** be
//! measured today are measured by `cargo test` rather than asserted in a document.
//!
//! ## Where the verification actually happens (`tauri-plugin-updater` 2.10.1)
//!
//! Read out of `~/.cargo/registry/src/*/tauri-plugin-updater-2.10.1/`, not from the docs:
//!
//! * `src/updater.rs:1453-1463` — `verify_signature(data, release_signature, pub_key)`:
//!   base64-decodes the configured `pubkey`, decodes the release signature, and calls
//!   `minisign_verify::PublicKey::verify(data, &signature, true)`. Every failure — a key that
//!   is not base64, a signature that is not base64, a signature made with a *different* key,
//!   a payload whose bytes were altered — returns `Err`, mapped to `Error::Minisign` /
//!   `Error::Base64` / `Error::SignatureUtf8` (`src/error.rs:52-60`). There is no branch that
//!   returns `Ok` without `verify` having succeeded.
//! * `src/updater.rs:712` — the single call site, inside `Update::download`, **after** the
//!   whole body has been buffered and **before** the buffer is returned. `install()` takes the
//!   bytes `download()` returned (`:717-726`), so no code path can install bytes that were not
//!   verified. Nothing gates this on `debug_assertions` or on a `dangerous*` flag; the three
//!   `dangerous*` options in `Config` relax TLS and the endpoint scheme, never the signature.
//! * **Unsigned manifest → rejected before any download.** `signature` is a non-`Option`
//!   `String` on `ReleaseManifestPlatform` (`src/updater.rs:76`), so a `platforms` entry
//!   without one fails serde. The flat form is rejected with an explicit message —
//!   `src/updater.rs:1422-1424`, *"the `signature` field was not set on the updater
//!   response"*. Either way the manifest never becomes a `RemoteRelease`, so no URL is ever
//!   fetched.
//! * **Keyless configuration → the app does not start.** `Config::pubkey` is a plain `String`
//!   with no `#[serde(default)]` (`src/config.rs:101` and the private mirror at `:122`), so a
//!   `plugins.updater` block without a `pubkey` fails to deserialize and the plugin's
//!   `setup` fails. That is the fail-closed property `lib.rs:68-82` describes, and
//!   [`tests::an_updater_configuration_without_a_pubkey_is_rejected`] measures it against the
//!   plugin's real deserializer.
//!
//! ## What is NOT measured here, and will not be until the first signed release
//!
//! There is no end-to-end run: producing a `latest.json` that this build would *accept*
//! requires the minisign **private** key for `3E1D6B1F3C9F300F`, which is held only by the
//! repo owner and by `TAURI_SIGNING_PRIVATE_KEY(_PASSWORD)` in CI, and is deliberately not in
//! this tree. So "a correctly signed update installs" and "a payload corrupted in flight is
//! refused at the last byte" stay unverified until a real signed release exists. `Update` is
//! not constructible from a test either — the type has no public constructor and `download`
//! needs a live endpoint. Everything below is therefore configuration- and source-level
//! evidence, and is labelled as such in `docs/DESKTOP-THREAT-MODEL.md` §3/8.

#[cfg(test)]
mod tests {
    use tauri_plugin_updater::Config;

    /// The `plugins.updater` block exactly as it ships, straight out of the real config file.
    ///
    /// `include_str!` rather than a copy: a test that carries its own idea of the
    /// configuration would keep passing after `tauri.conf.json` changed, which is the one
    /// failure mode that matters here.
    fn shipped_updater_json() -> serde_json::Value {
        let config: serde_json::Value =
            serde_json::from_str(include_str!("../tauri.conf.json")).expect("tauri.conf.json");
        config["plugins"]["updater"].clone()
    }

    /// The block parses with **the plugin's own `Config` deserializer** — the same code path
    /// `tauri_plugin_updater::init()` runs at startup — and carries a non-empty key.
    ///
    /// This is what O12 actually closed: before the block existed, this deserialization is
    /// what failed, with `invalid type: null, expected struct Config`, and the app exited 101
    /// before opening a window.
    #[test]
    fn the_shipped_updater_configuration_parses_and_carries_a_key() {
        let config: Config = serde_json::from_value(shipped_updater_json())
            .expect("plugins.updater must deserialize with the plugin's own Config");

        assert!(
            !config.pubkey.trim().is_empty(),
            "the updater is configured without a verification key"
        );
        assert_eq!(config.endpoints.len(), 1, "{:?}", config.endpoints);
        assert_eq!(
            config.endpoints[0].scheme(),
            "https",
            "the update manifest must not be fetched over cleartext: {}",
            config.endpoints[0]
        );
    }

    /// Signature verification cannot be switched off by configuration, and this build does not
    /// try: none of the three `dangerous*` escape hatches is set.
    ///
    /// They relax TLS and the endpoint scheme only (`config.rs:145-163` handles the scheme);
    /// none of them reaches `verify_signature`. Asserting them anyway keeps a future
    /// "just to get the release out" edit visible.
    #[test]
    fn no_dangerous_transport_escape_hatch_is_enabled() {
        let config: Config = serde_json::from_value(shipped_updater_json()).expect("config");

        assert!(!config.dangerous_insecure_transport_protocol);
        assert!(!config.dangerous_accept_invalid_certs);
        assert!(!config.dangerous_accept_invalid_hostnames);
    }

    /// **Fail-closed, measured.** Remove the key and the configuration no longer exists: the
    /// plugin's `Config` refuses to deserialize, so an updater with nothing to verify against
    /// cannot be built, let alone run.
    ///
    /// This is the strongest form of "imzasız manifest reddi" reachable without the private
    /// key — the rejection happens one layer earlier than the manifest, at the point where a
    /// build would otherwise be capable of installing anything at all.
    #[test]
    fn an_updater_configuration_without_a_pubkey_is_rejected() {
        let mut json = shipped_updater_json();
        json.as_object_mut()
            .expect("the updater block is an object")
            .remove("pubkey")
            .expect("sanity: the shipped block has a pubkey to remove");

        let parsed = serde_json::from_value::<Config>(json);
        assert!(
            parsed.is_err(),
            "a keyless updater configuration was accepted — the fail-closed property is gone"
        );
    }

    /// An empty key is not a key, and it is the shape a placeholder takes.
    ///
    /// `Config` accepts `pubkey: ""` (it is a `String`, and serde has no opinion). The
    /// rejection then happens later and quietly, inside `verify_signature` →
    /// `PublicKey::decode`. Pinning it here documents which layer refuses it.
    #[test]
    fn an_empty_pubkey_is_not_what_ships() {
        let mut json = shipped_updater_json();
        json["pubkey"] = serde_json::Value::String(String::new());

        let parsed: Config = serde_json::from_value(json).expect("serde accepts an empty string");
        assert!(parsed.pubkey.is_empty(), "sanity");

        let shipped: Config = serde_json::from_value(shipped_updater_json()).expect("config");
        assert!(
            !shipped.pubkey.is_empty(),
            "the shipped key must not be the empty placeholder"
        );
    }

    /// Minimal base64 decoder, for the key inspection below.
    ///
    /// Written out rather than pulled in: `base64` reaches this crate only transitively
    /// (through the updater plugin and `reqwest`), and a transitive dependency is not nameable
    /// from here. Thirty lines of table lookup is a smaller change than a new dependency in
    /// `Cargo.toml`, which is not this strand's file to edit.
    fn base64_decode(input: &str) -> Vec<u8> {
        const ALPHABET: &[u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

        let mut out = Vec::new();
        let mut buffer = 0u32;
        let mut bits = 0u32;

        for byte in input.bytes().filter(|b| !b.is_ascii_whitespace()) {
            if byte == b'=' {
                break;
            }
            let value = ALPHABET
                .iter()
                .position(|c| *c == byte)
                .unwrap_or_else(|| panic!("`{}` is not base64", byte as char));

            buffer = (buffer << 6) | value as u32;
            bits += 6;
            if bits >= 8 {
                bits -= 8;
                out.push((buffer >> bits) as u8);
            }
        }

        out
    }

    /// The configured key is a **real** minisign Ed25519 public key, and it is the one the
    /// release pipeline signs with: key id `3E1D6B1F3C9F300F`.
    ///
    /// Why this is worth a test rather than a glance. `Config` only requires a `String`; a
    /// truncated key, a key for a signing pair nobody holds, or the placeholder someone pastes
    /// "just to get the build to start" would all deserialize fine and then fail at exactly
    /// one moment — the first real update, on a user's machine. This decodes the two base64
    /// layers minisign uses (outer: the whole `.pub` file; inner: the key line), checks the
    /// 42-byte `Ed` + key-id + key layout, and checks that the id embedded in the key **agrees
    /// with the untrusted comment that names it** — a mismatch is the signature of a key that
    /// was assembled by hand.
    ///
    /// The id is little-endian in the key bytes, which is why it is reversed before hex.
    #[test]
    fn the_configured_pubkey_is_the_real_minisign_key_for_3e1d6b1f3c9f300f() {
        const EXPECTED_KEY_ID: &str = "3E1D6B1F3C9F300F";

        let config: Config = serde_json::from_value(shipped_updater_json()).expect("config");

        // Layer 1: the config value is the base64 of the whole `.pub` file.
        let pub_file = String::from_utf8(base64_decode(&config.pubkey))
            .expect("the pubkey must decode to the text of a minisign .pub file");
        let mut lines = pub_file.lines();
        let comment = lines.next().expect("untrusted comment line");
        let key_line = lines.next().expect("key line");

        assert!(
            comment.starts_with("untrusted comment:"),
            "not a minisign .pub file: {comment}"
        );
        assert!(
            comment.contains(EXPECTED_KEY_ID),
            "the .pub comment names a different key than the release pipeline signs with: \
             {comment}"
        );

        // Layer 2: the key line is base64 of `Ed` + 8-byte key id + 32-byte Ed25519 key.
        let raw = base64_decode(key_line);
        assert_eq!(raw.len(), 42, "minisign public keys are 42 bytes: {raw:?}");
        assert_eq!(&raw[0..2], b"Ed", "unexpected signature algorithm: {:?}", &raw[0..2]);

        let key_id: String = raw[2..10]
            .iter()
            .rev()
            .map(|byte| format!("{byte:02X}"))
            .collect();
        assert_eq!(
            key_id, EXPECTED_KEY_ID,
            "the key id inside the key does not match the comment that names it"
        );

        // Negative control, in-test: the comparison above has to discriminate. Flip one byte of
        // the key id and the same derivation must stop matching — otherwise this test would
        // pass for any 42 bytes beginning with `Ed`.
        let mut tampered = raw.clone();
        tampered[2] ^= 0x01;
        let tampered_id: String = tampered[2..10]
            .iter()
            .rev()
            .map(|byte| format!("{byte:02X}"))
            .collect();
        assert_ne!(
            tampered_id, EXPECTED_KEY_ID,
            "the key-id check does not discriminate — it would accept a key it should not"
        );
    }

    /// The plugin registration stays unconditional (O12).
    ///
    /// It used to sit behind `#[cfg(not(debug_assertions))]`, and that is precisely why the
    /// keyless-config panic reached a release binary: CI builds with `--debug`, so the block
    /// was never compiled there and no job could have caught it. A source-level assert because
    /// the property is about which build the code exists in — a test binary is one build and
    /// cannot observe the others.
    #[test]
    fn the_updater_plugin_is_registered_in_every_build() {
        let lib = include_str!("lib.rs");
        let code: String = lib
            .lines()
            .map(|line| match line.find("//") {
                Some(at) => &line[..at],
                None => line,
            })
            .collect::<Vec<_>>()
            .join("\n");

        assert!(
            code.contains("tauri_plugin_updater::Builder"),
            "the updater plugin is no longer registered at all"
        );
        assert!(
            !code.contains(concat!("cfg(not(debug_", "assertions))")),
            "the updater registration (or another one) went back behind a debug_assertions \
             gate — CI builds with --debug and would never compile it"
        );
    }
}
