use soar_ota::{rollout_bucket, ApplyMode, Manifest, sign, verify};
use ed25519_dalek::SigningKey;
fn hex(b: &[u8]) -> String { b.iter().map(|x| format!("{x:02x}")).collect() }
fn main() {
    // Prints test vectors computed by the real Soar code: rollout buckets, canonical manifest bytes, signatures.
    let mut buckets = serde_json::Map::new();
    let mut list = vec![];
    for (d, a, v) in [("device-1","dev.soar.demo",1u64),("device-1","dev.soar.demo",2),("device-2","dev.soar.demo",1),
        ("00000000-0000-4000-8000-000000000000","dev.soar.demo",3),("","a",0),("thiết-bị-ốc","ứng.dụng",18446744073709551615),
        ("abc","dev.soar.demo",4294967296),("device-1","other.app",1),("d","a",9223372036854775808)] {
        list.push(serde_json::json!({"device_id":d,"app_id":a,"version":v.to_string(),"bucket":rollout_bucket(d,a,v)}));
    }
    buckets.insert("buckets".into(), list.into());
    let key = SigningKey::from_bytes(&[7u8; 32]);
    let mut cases = vec![];
    for (app, ch, ver, minh, ks, sha, size, pct, mode) in [
        ("dev.soar.demo","production",5u64,29u32,vec!["storage","network","camera","network"],"00".repeat(32),123u64,25u8,ApplyMode::OnResume),
        ("ứng.dụng","stag/ing",9223372036854775807,0,vec![],"ab".repeat(32),0,0,ApplyMode::NextLaunch),
        ("big","u64",18446744073709551615,7,vec!["k"],"cd".repeat(32),18446744073709551615,99,ApplyMode::OnResume),
        ("a","b",1,4294967295,vec!["z","Z","a","é"],"ff".repeat(32),9223372036854775807,100,ApplyMode::Immediate),
    ] {
        let m = Manifest{app_id:app.into(),channel:ch.into(),version:ver,min_host_ver:minh,kernels:ks.iter().map(|s|s.to_string()).collect(),
            bundle_sha256:sha,bundle_size:size,rollout_percent:pct,apply_mode:mode,signature:String::new()};
        let canon = hex(&m.canonical_bytes());
        let s = sign(m.clone(), &key);
        verify(&s, &key.verifying_key()).ok();
        cases.push(serde_json::json!({"manifest": serde_json::from_slice::<serde_json::Value>(&s.to_json().unwrap()).unwrap(), "version_str": ver.to_string(), "size_str": size.to_string(), "canonical_hex": canon}));
    }
    buckets.insert("manifests".into(), cases.into());
    buckets.insert("signing_seed_hex".into(), hex(&[7u8;32]).into());
    buckets.insert("public_key_hex".into(), hex(key.verifying_key().as_bytes()).into());
    println!("{}", serde_json::to_string_pretty(&buckets).unwrap());
}
