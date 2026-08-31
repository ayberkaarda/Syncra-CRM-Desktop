//! Retry backoff for the sync loop.
//!
//! `SYNCDESKTOP.md` §5.5: `1s, 2s, 4s, …, 300s` with ±20% jitter.

use std::time::Duration;

/// First delay after a failure.
pub const BASE_SECS: u64 = 1;
/// Ceiling of the exponential ramp.
pub const MAX_SECS: u64 = 300;
/// Jitter amplitude, as a percentage of the nominal delay.
pub const JITTER_PERCENT: u64 = 20;

/// Nominal delay for `attempt` (0 = the first retry), before jitter.
pub fn nominal(attempt: u32) -> Duration {
    let shift = attempt.min(16);
    let secs = BASE_SECS.saturating_mul(1u64 << shift).min(MAX_SECS);
    Duration::from_secs(secs)
}

/// Delay for `attempt` with ±20% jitter applied.
///
/// The jitter source is the process clock rather than a `rand` dependency: this is retry
/// spacing, not key material, and the dependency list is fixed by `SYNCDESKTOP.md` §5.1.
pub fn with_jitter(attempt: u32) -> Duration {
    let base = nominal(attempt).as_millis() as u64;
    let span = base * JITTER_PERCENT / 100;
    if span == 0 {
        return Duration::from_millis(base);
    }
    let noise = entropy() % (span * 2 + 1);
    Duration::from_millis(base + noise - span)
}

fn entropy() -> u64 {
    use std::time::{SystemTime, UNIX_EPOCH};
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.subsec_nanos() as u64 ^ d.as_secs())
        .unwrap_or(0)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ramp_doubles_then_saturates() {
        assert_eq!(nominal(0), Duration::from_secs(1));
        assert_eq!(nominal(1), Duration::from_secs(2));
        assert_eq!(nominal(2), Duration::from_secs(4));
        assert_eq!(nominal(8), Duration::from_secs(256));
        assert_eq!(nominal(9), Duration::from_secs(MAX_SECS));
        assert_eq!(nominal(1000), Duration::from_secs(MAX_SECS));
    }

    #[test]
    fn jitter_stays_within_twenty_percent() {
        for attempt in 0..12u32 {
            let base = nominal(attempt).as_millis() as u64;
            let span = base * JITTER_PERCENT / 100;
            for _ in 0..64 {
                let got = with_jitter(attempt).as_millis() as u64;
                assert!(
                    got >= base - span && got <= base + span,
                    "attempt {attempt}: {got} outside {base}±{span}"
                );
            }
        }
    }
}
