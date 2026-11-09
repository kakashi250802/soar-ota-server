//! Plays the part of a device: fetches the static layout over HTTP with the real `soar_ota` updater
//! types, verifies the signature, checks eligibility and the bundle hash, and stages the bundle.
use std::collections::BTreeSet;
use soar_ota::updater::{fetch_from, public_key, Download, Next, Updater};
use soar_ota::{HostInfo, Manifest, publish::bundle_path};

// Usage: client BASE_URL APP CHANNEL PUBKEY_FILE DEVICE_ID
fn main() {
    let a: Vec<String> = std::env::args().collect();
    let (base, app, channel, pubf, device) = (&a[1], &a[2], &a[3], &a[4], &a[5]);
    let fetch = fetch_from(base).unwrap();
    let manifest = Manifest::from_json(&fetch.get(&format!("{app}/{channel}/manifest.json")).unwrap()).unwrap();
    println!("manifest v{} rollout {}% mode {:?}", manifest.version, manifest.rollout_percent, manifest.apply_mode);
    let bundle = fetch.get(&format!("{app}/{channel}/{}", bundle_path(&manifest))).unwrap();
    let key = public_key(std::path::Path::new(pubf)).unwrap();
    let host = HostInfo { app_id: app.clone(), channel: channel.clone(), host_ver: 29,
        kernels: ["storage", "network", "camera"].iter().map(|s| s.to_string()).collect::<BTreeSet<_>>(), device_id: device.clone() };
    let dir = std::env::temp_dir().join(format!("soar-ota-e2e-{}", std::process::id()));
    let mut u = Updater::open(dir, key, host, 0).unwrap();
    match u.offer(&Download { manifest, bundle }) {
        Ok(Next::Wait) => { println!("STAGED (verified signature, eligibility, size and sha256)"); 
            if let Ok(Next::Swap(b)) = u.resumed() { println!("resume would swap in {} bytes", b.len()); } }
        Ok(Next::Swap(b)) => println!("STAGED and swap now: {} bytes", b.len()),
        Err(e) => println!("REJECTED: {e}"),
    }
}
