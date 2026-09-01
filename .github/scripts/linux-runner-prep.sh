#!/usr/bin/env bash
# Linux runner preparation, shared by every Linux leg of desktop-ci.yml.
#
# WHY THIS IS A FILE AND NOT TWO COPIES OF A `run:` BLOCK
# It used to be two copies, and on 2026-09-01 they drifted within a single commit: `libgbm-dev`
# was added to `desktop-rust`'s list and not to `desktop-tauri-build`'s, so the workspace job
# went green and the bundle job failed on the same `-lgbm` link error the fix was written for.
# GitHub Actions has no YAML anchors, so the only way to have one list is to have one file.
set -euo pipefail

echo "--- disk before ---"
df -h /

# A GitHub-hosted Linux runner has roughly 14 GB free on `/`, and this workspace does not fit in
# it: a full local debug tree measures 45.6 GB (incremental 23.9, deps 15.7, build 3.4). The
# workflow-level CARGO_INCREMENTAL=0 and CARGO_PROFILE_DEV_DEBUG=0 remove the two largest shares;
# this reclaims the preinstalled toolchains the repo never touches. Nothing below is a build
# dependency of this project — if a future job needs one, this is where it went.
sudo rm -rf \
  /usr/local/lib/android \
  /usr/share/dotnet \
  /opt/ghc \
  /opt/hostedtoolcache/CodeQL || true

echo "--- disk after reclaim ---"
df -h /

# Tauri's own documented Linux prerequisites, plus exactly three additions. Each addition is here
# because a mechanism read out of the crate sources proves it cannot arrive any other way:
#
#   libclang-dev        : `pipewire-sys` and `libspa-sys` both run bindgen in their build.rs
#                         (verified in the vendored 0.10.1 sources), and bindgen dlopens libclang
#                         at build time. No Rust dependency can supply a system libclang.
#   libpipewire-0.3-dev : both -sys crates declare `links` (`pipewire-0.3`, `libspa-0.2`) and
#                         resolve through system-deps -> pkg-config, asking for those modules by
#                         name. Nothing in the graph vendors either. Ubuntu's package ships
#                         libpipewire-0.3.pc and depends on libspa-0.2-dev, so one package covers
#                         both probes; if a future image splits them, the pkg-config error names
#                         the missing module exactly.
#   libgbm-dev          : added 2026-09-01 after a run failed with
#                         `rust-lld: error: unable to find library -lgbm` — the missing dependency
#                         named itself. `gbm-sys 0.4.0`'s src/lib.rs:13 carries a bare
#                         `#[link(name = "gbm")]` with no build-script probe and no vendored
#                         library. Ubuntu ships only libgbm.so.1 at runtime; the libgbm.so symlink
#                         the linker wants lives in libgbm-dev. It reaches us through
#                         xcap -> libwayshot-xcap -> gbm, i.e. F5-8 screenshot-to-ticket capture.
#
# The wider "probably needed" set (libegl-dev, libxcb*-dev, libdrm-dev, libwayland-dev,
# libdbus-1-dev) is deliberately NOT pre-added, and the gbm episode argues for keeping it that way
# rather than against it: one red run, one line naming its own cause. Before adding gbm the sibling
# sys-crates in the same chain were checked the same way — drm-sys, drm-ffi, drm, wayland-sys,
# khronos-egl and gl all resolve through pkg-config or a build probe, none carries a bare
# `#[link]`, and all of them had already linked in the failing run. A padded list would permanently
# hide which dependencies are real; a missing apt package fails loudly, by name, in seconds.
sudo apt-get update
sudo apt-get install -y \
  libwebkit2gtk-4.1-dev \
  libayatana-appindicator3-dev \
  librsvg2-dev \
  libxdo-dev \
  libssl-dev \
  patchelf \
  build-essential \
  curl \
  wget \
  file \
  libclang-dev \
  libpipewire-0.3-dev \
  libgbm-dev

echo "--- disk after apt ---"
df -h /
