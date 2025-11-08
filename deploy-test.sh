#!/usr/bin/env bash
set -uo pipefail

# Ensure TMPDIR exists (for mktemp etc.)
: "${TMPDIR:=$(mktemp -d)}"

# =====================================================
# PATH SETUP
# =====================================================
script_dir="$(cd -- "$(dirname -- "$0")" && pwd -P)"
config_file="$script_dir/deploy-test.cfg"

# =====================================================
# LOAD CONFIG (handles #/; comments, blank lines)
# =====================================================
if [[ ! -f "$config_file" ]]; then
  echo "[ERROR] Config file not found: $config_file"
  exit 1
fi

# Clear (so old env doesn’t leak)
unset PLUGIN_NAME PLUGIN_TAGS PLUGIN_SLUG HEADER_SCRIPT CHANGELOG_FILE STATIC_FILE ZIP_NAME GENERATOR_SCRIPT
unset GITHUB_REPO TOKEN_FILE GITHUB_TOKEN DEST_DIR DEPLOY_TARGET DEPLOY_TARGETS
unset LOCAL_DEST_DIR GITHUB_OWNER GITHUB_TAG_PREFIX GITHUB_RELEASE_PRERELEASE
unset GDRIVE_SYNC_DIR GDRIVE_ZIP_NAME GDRIVE_MANIFEST_NAME GDRIVE_ZIP_FILE_ID GDRIVE_MANIFEST_FILE_ID

# Parse KEY=VALUE (ignore comments and blanks)
while IFS= read -r line || [[ -n "$line" ]]; do
  line="${line//$'\r'/}"
  [[ -z "$line" || "${line:0:1}" == "#" || "${line:0:1}" == ";" ]] && continue
  key="${line%%=*}"
  val="${line#*=}"
  key="$(echo "$key" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
  val="$(echo "$val" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
  eval "$key=\"\$val\""
done < "$config_file"

# =====================================================
# CONSTANTS / SHARED TOOLS (same defaults as before)
# =====================================================
HEADER_SCRIPT="${HEADER_SCRIPT:-C:/Ignore By Avast/0. PATHED Items/Plugins/deployscripts/myplugin_headers.php}"
TOKEN_FILE="${TOKEN_FILE:-C:/Ignore By Avast/0. PATHED Items/Plugins/deployscripts/github_token.txt}"
GENERATOR_SCRIPT="${GENERATOR_SCRIPT:-C:/Ignore By Avast/0. PATHED Items/Plugins/deployscripts/generate_index.php}"

# =====================================================
# DEFAULTS / VALIDATION
# =====================================================
if [[ -z "${PLUGIN_SLUG:-}" ]]; then
  echo "[ERROR] PLUGIN_SLUG is not defined in deploy.cfg"
  exit 1
fi
if [[ -z "${GITHUB_REPO:-}" ]]; then
  echo "[WARN] GITHUB_REPO not defined. GitHub target will be unavailable unless set in deploy.cfg."
fi

ZIP_NAME="${ZIP_NAME:-$PLUGIN_SLUG.zip}"
CHANGELOG_FILE="${CHANGELOG_FILE:-changelog.txt}"
STATIC_FILE="${STATIC_FILE:-static.txt}"
PLUGIN_NAME="${PLUGIN_NAME:-$PLUGIN_SLUG}"
PLUGIN_TAGS="${PLUGIN_TAGS:-}"

# Back-compat: if old single-target var is present, use it; otherwise DEPLOY_TARGETS
if [[ -n "${DEPLOY_TARGET:-}" && -z "${DEPLOY_TARGETS:-}" ]]; then
  DEPLOY_TARGETS="$DEPLOY_TARGET"
fi
DEPLOY_TARGETS="${DEPLOY_TARGETS:-github}"

# Derived paths
repo_root="$script_dir"
plugin_dir="$script_dir/$PLUGIN_SLUG"
plugin_file="$plugin_dir/$PLUGIN_SLUG.php"
readme_file="$plugin_dir/readme.txt"
temp_readme="$plugin_dir/readme_temp.txt"
static_subfolder="$repo_root/uupd"

# =====================================================
# VERIFY REQUIRED FILES
# =====================================================
[[ -f "$plugin_file" ]] || { echo "[ERROR] Plugin file not found: $plugin_file"; exit 1; }
[[ -f "$CHANGELOG_FILE" ]] || { echo "[ERROR] Changelog file not found: $CHANGELOG_FILE"; exit 1; }
[[ -f "$STATIC_FILE"   ]] || { echo "[ERROR] Static readme file not found: $STATIC_FILE"; exit 1; }

# =====================================================
# RUN HEADER SCRIPT (updates plugin headers if needed)
# =====================================================
php "$HEADER_SCRIPT" "$plugin_file"

# =====================================================
# EXTRACT METADATA FROM HEADERS
# (robust to either "Header:" or "* Header:" styles)
# =====================================================
requires_at_least="$(
  grep -m1 -E '^(Requires at least:|[[:space:]]*\*[[:space:]]*Requires at least:)' "$plugin_file" \
    | sed -E 's/.*Requires at least:[[:space:]]*//' || true
)"
tested_up_to="$(
  grep -m1 -E '^(Tested up to:|[[:space:]]*\*[[:space:]]*Tested up to:)' "$plugin_file" \
    | sed -E 's/.*Tested up to:[[:space:]]*//' || true
)"
requires_php="$(
  grep -m1 -E '^(Requires PHP:|[[:space:]]*\*[[:space:]]*Requires PHP:)' "$plugin_file" \
    | sed -E 's/.*Requires PHP:[[:space:]]*//' || true
)"
version="$(
  grep -m1 -E '^(Version:|[[:space:]]*\*[[:space:]]*Version)' "$plugin_file" \
    | sed -E 's/.*Version[: ]+[[:space:]]*//; s/\r//; s/[[:space:]]+$//' || true
)"

if [[ -z "$version" ]]; then
  version_line="$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version' "$plugin_file" || true)"
  version="$(sed -E 's/.*Version[: ]+[[:space:]]*//; s/\r//; s/[[:space:]]+$//' <<< "$version_line")"
fi

[[ -n "$version" ]] || { echo "[ERROR] Could not extract Version from $plugin_file"; exit 1; }

# =====================================================
# GENERATE STATIC index.json FOR GITHUB DELIVERY
# (used by your UUPD GitHub path)
# =====================================================
echo "[INFO] Generating index.json for GitHub delivery..."
github_user="${GITHUB_REPO%%/*}"
repo_name="${GITHUB_REPO#*/}"
cdn_path="https://raw.githubusercontent.com/$github_user/$repo_name/main/uupd"

mkdir -p "$static_subfolder"

php "$GENERATOR_SCRIPT" \
  "$plugin_file" \
  "$CHANGELOG_FILE" \
  "$static_subfolder" \
  "$github_user" \
  "$cdn_path" \
  "$repo_name" \
  "$repo_name" \
  "$STATIC_FILE" \
  "$ZIP_NAME"

if [[ -f "$static_subfolder/index.json" ]]; then
  echo "[OK] index.json generated: $static_subfolder/index.json"
else
  echo "[WARN] Failed to generate index.json (GitHub UUPD path will lack index.json)"
fi

# =====================================================
# CREATE README.TXT
# =====================================================
{
  echo "=== $PLUGIN_NAME ==="
  echo "Contributors: reallyusefulplugins"
  echo "Donate link: https://reallyusefulplugins.com/donate"
  echo "Tags: $PLUGIN_TAGS"
  echo "Requires at least: $requires_at_least"
  echo "Tested up to: $tested_up_to"
  echo "Stable tag: $version"
  echo "Requires PHP: $requires_php"
  echo "License: GPL-2.0-or-later"
  echo "License URI: https://www.gnu.org/licenses/gpl-2.0.html"
  echo
} > "$temp_readme"

cat "$STATIC_FILE" >> "$temp_readme"
echo >> "$temp_readme"
echo "== Changelog ==" >> "$temp_readme"
cat "$CHANGELOG_FILE" >> "$temp_readme"

if [[ -f "$readme_file" ]]; then
  cp -f "$readme_file" "$readme_file.bak"
fi
mv -f "$temp_readme" "$readme_file"

# =====================================================
# GIT COMMIT AND PUSH CHANGES
# =====================================================
pushd "$plugin_dir" >/dev/null
git add -A

if ! git diff --cached --quiet; then
  git commit -m "Version $version Release"
  git push origin main
  echo "[OK] Git commit and push complete."
else
  echo "[INFO] No changes to commit."
fi
popd >/dev/null

# =====================================================
# ZIP PLUGIN FOLDER
# =====================================================
sevenzip_win="/c/Program Files/7-Zip/7z.exe"
zip_file="$script_dir/$ZIP_NAME"

if [[ -x "$sevenzip_win" ]]; then
  pushd "$script_dir" >/dev/null
  "$sevenzip_win" a -tzip "$zip_file" "$PLUGIN_SLUG" >/dev/null
  popd >/dev/null
else
  # Fallback to tar -a (creates zip if extension is .zip)
  pushd "$script_dir" >/dev/null
  tar -a -c -f "$zip_file" "$PLUGIN_SLUG"
  popd >/dev/null
fi

if [[ -f "$zip_file" ]]; then
  echo "[OK] Zipped to: $zip_file"
else
  echo "[ERROR] Failed to create archive."
  exit 1
fi

# =====================================================
# DEPLOY HELPERS
# =====================================================
die(){ echo "[ERROR] $*" >&2; exit 1; }
info(){ echo "[INFO] $*"; }
ok(){ echo "[OK] $*"; }

# ---------- Deploy: LOCAL (private folder) ----------
deploy_local() {
  : "${LOCAL_DEST_DIR:?LOCAL_DEST_DIR missing in deploy.cfg}"
  mkdir -p "$LOCAL_DEST_DIR" || die "Cannot create LOCAL_DEST_DIR"
  cp -f "$zip_file" "$LOCAL_DEST_DIR/" || die "Copy to LOCAL_DEST_DIR failed"
  ok "Local deploy -> $LOCAL_DEST_DIR/$(basename "$zip_file")"
}

# ---------- Deploy: GITHUB Release ----------
# Requires: GITHUB_REPO=owner/repo and GITHUB_TOKEN set or token file present
deploy_github() {
  : "${GITHUB_REPO:?GITHUB_REPO missing}"
  # Get token from env or file
  if [[ -z "${GITHUB_TOKEN:-}" && -f "$TOKEN_FILE" ]]; then
    GITHUB_TOKEN="$(tr -d '\r\n' < "$TOKEN_FILE")"
  fi
  [[ -n "${GITHUB_TOKEN:-}" ]] || die "GITHUB_TOKEN not available (set env var or provide TOKEN_FILE)"

  local release_tag="${GITHUB_TAG_PREFIX:-}v$version"
  local prerelease="${GITHUB_RELEASE_PRERELEASE:-0}"

  # Prepare body
  local body_file changelog_body
  body_file="$(mktemp)"
  changelog_body="$(sed ':a;N;$!ba;s/\r//g' "$CHANGELOG_FILE" \
    | sed 's/\\/\\\\/g; s/"/\\"/g; s/$/\\n/' \
    | tr -d '\n')"
  cat >"$body_file" <<JSON
{
  "tag_name": "$release_tag",
  "name": "$version",
  "body": "$changelog_body",
  "draft": false,
  "prerelease": $( [[ "$prerelease" == "1" ]] && echo "true" || echo "false" )
}
JSON

  # Check existing
  status=$(curl -sS -o "$TMPDIR/github_release_response.json" -w "%{http_code}" \
    -H "Authorization: token $GITHUB_TOKEN" \
    -H "Accept: application/vnd.github+json" \
    "https://api.github.com/repos/$GITHUB_REPO/releases/tags/$release_tag" || true)

  release_id=""
  if [[ "$status" == "200" ]]; then
    release_id="$(grep -m1 -E '"id":[[:space:]]*[0-9]+' "$TMPDIR/github_release_response.json" | head -1 | sed -E 's/.*"id":[[:space:]]*([0-9]+).*/\1/')"
    info "Release exists. Updating body (id=$release_id)..."
    curl -sS -X PATCH "https://api.github.com/repos/$GITHUB_REPO/releases/$release_id" \
      -H "Authorization: token $GITHUB_TOKEN" \
      -H "Accept: application/vnd.github+json" \
      -H "Content-Type: application/json" \
      --data-binary "@$body_file" >/dev/null
  else
    info "Creating new release..."
    curl -sS -X POST "https://api.github.com/repos/$GITHUB_REPO/releases" \
      -H "Authorization: token $GITHUB_TOKEN" \
      -H "Accept: application/vnd.github+json" \
      -H "Content-Type: application/json" \
      --data-binary "@$body_file" > "$TMPDIR/github_release_response.json"
    release_id="$(grep -m1 -E '"id":[[:space:]]*[0-9]+' "$TMPDIR/github_release_response.json" | head -1 | sed -E 's/.*"id":[[:space:]]*([0-9]+).*/\1/')"
  fi

  rm -f "$body_file"
  [[ -n "$release_id" ]] || { echo "[ERROR] Could not determine release ID."; cat "$TMPDIR/github_release_response.json" || true; exit 1; }
  ok "Using Release ID: $release_id"

  # Upload asset (replace if exists)
  asset_name="$(basename "$zip_file")"
  curl -sS -X POST "https://uploads.github.com/repos/$GITHUB_REPO/releases/$release_id/assets?name=$asset_name" \
    -H "Authorization: token $GITHUB_TOKEN" \
    -H "Accept: application/vnd.github+json" \
    -H "Content-Type: application/zip" \
    --data-binary @"$zip_file" >/dev/null || die "Asset upload failed"

  ok "GitHub upload complete"
}

# ---------- Deploy: Google Drive (UUPD) ----------
# Uses Drive for Desktop sync with fixed filenames -> stable file IDs
deploy_drive() {
  : "${GDRIVE_SYNC_DIR:?Missing GDRIVE_SYNC_DIR}"
  : "${GDRIVE_ZIP_NAME:?Missing GDRIVE_ZIP_NAME}"
  : "${GDRIVE_MANIFEST_NAME:?Missing GDRIVE_MANIFEST_NAME}"

  # helpers
  extract_drive_id() {
    # Accept either a raw fileId or any Google Drive URL and return the fileId
    local in="$1"
    # strip quotes/spaces
    in="${in//\"/}"; in="$(echo "$in" | tr -d '[:space:]')"
    # raw id?
    if [[ "$in" =~ ^[A-Za-z0-9_-]{10,}$ ]]; then
      echo "$in"; return 0
    fi
    # /file/d/<id>/view...
    if [[ "$in" =~ /d/([A-Za-z0-9_-]{10,})/view ]]; then
      echo "${BASH_REMATCH[1]}"; return 0
    fi
    # id=<id>
    if [[ "$in" =~ (^|[?&])id=([A-Za-z0-9_-]{10,}) ]]; then
      echo "${BASH_REMATCH[2]}"; return 0
    fi
    # share links sometimes /open?id=<id>
    if [[ "$in" =~ open\?id=([A-Za-z0-9_-]{10,}) ]]; then
      echo "${BASH_REMATCH[1]}"; return 0
    fi
    # unknown
    echo ""; return 1
  }

  # Normalize/resolve IDs (accept raw ID or URL)
  local ZIP_ID_RAW="${GDRIVE_ZIP_FILE_ID:-}"
  local MANIFEST_ID_RAW="${GDRIVE_MANIFEST_FILE_ID:-}"
  local ZIP_ID="$(extract_drive_id "$ZIP_ID_RAW")"
  local MANIFEST_ID="$(extract_drive_id "$MANIFEST_ID_RAW")"

  local drive_dir="$(tr -d '\r' <<<"$GDRIVE_SYNC_DIR")"
  local drive_zip="$drive_dir/$GDRIVE_ZIP_NAME"
  local drive_manifest="$drive_dir/$GDRIVE_MANIFEST_NAME"

  mkdir -p "$drive_dir" || die "Cannot create $drive_dir"

  # Ensure the two files exist locally so Drive can assign/keep IDs
  if [[ ! -f "$drive_zip" ]]; then
    info "Bootstrap: creating initial ZIP at $drive_zip"
    # copy our freshly built zip as the initial placeholder
    cp -f "$zip_file" "$drive_zip" || die "Bootstrap copy to Drive folder failed"
  else
    info "Copying ZIP to Drive-synced path: $drive_zip"
    cp -f "$zip_file" "$drive_zip" || die "Copy to Drive folder failed"
  fi

  if [[ ! -f "$drive_manifest" ]]; then
    info "Bootstrap: creating empty manifest at $drive_manifest"
    printf "{}" > "$drive_manifest" || die "Cannot create manifest file"
  fi

  # Optionally help the user open the folder to grab IDs on first run
  if [[ "${AUTO_OPEN_EXPLORER:-0}" == "1" ]] && { [[ -z "$ZIP_ID" ]] || [[ -z "$MANIFEST_ID" ]] ; }; then
    # Windows Git Bash: explorer handles either / or \ paths fine
    command -v explorer >/dev/null 2>&1 && explorer "$drive_dir" >/dev/null 2>&1 || true
  fi

  # Compute SHA-256 of the synced ZIP
  info "Computing SHA-256 (PHP)…"
  local sha256
  sha256="$(php -r "echo hash_file('sha256', '$drive_zip');")" || die "SHA-256 failure"
  [[ -n "$sha256" ]] || die "SHA-256 is empty"

  # If ZIP_ID still missing, decide whether to proceed
  if [[ -z "$ZIP_ID" ]]; then
    echo
    echo "[WARN] GDRIVE_ZIP_FILE_ID not set (or could not be parsed)."
    echo "       1) In Drive: right-click '$GDRIVE_ZIP_NAME' -> View in web -> copy the ID from /file/d/<ID>/view"
    echo "       2) Put EITHER the raw ID OR the full link into GDRIVE_ZIP_FILE_ID in your cfg."
    echo "       3) Re-run the deploy."
    if [[ "${ALLOW_EMPTY_DRIVE_ID:-0}" != "1" ]]; then
      echo "[SAFE-STOP] Not writing manifest without a valid ZIP ID (to avoid publishing a broken update feed)."
      return 1
    fi
    echo "[BOOTSTRAP] ALLOW_EMPTY_DRIVE_ID=1 -> writing a placeholder manifest (clients will ignore it)."
  fi

  # Build URLs (may be empty if we’re bootstrapping)
  local package_url=""
  if [[ -n "$ZIP_ID" ]]; then
    package_url="https://drive.google.com/uc?export=download&id=$ZIP_ID"
  fi

  # Write UUPD manifest (pretty JSON)
  info "Writing UUPD manifest: $drive_manifest"
  php -r '
    $ver = getenv("UUPD_VERSION");
    $zipId = getenv("UUPD_ZIP_ID");
    $sha = getenv("UUPD_SHA");
    $pkg = $zipId ? ("https://drive.google.com/uc?export=download&id=".$zipId) : "";
    $m = [
      "version"       => $ver,
      "drive_file_id" => $zipId ?: "",
      "package"       => $pkg,
      "checksum"      => ["algo"=>"sha256","hash"=>$sha],
      // add a gentle breadcrumb in bootstrap mode; UUPD readers will ignore unknown fields
      "_note"         => $zipId ? "" : "Bootstrap: set GDRIVE_ZIP_FILE_ID in deploy cfg to finalize package URL"
    ];
    $out = json_encode($m, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if ($out===false) { fwrite(STDERR, "JSON encode failed\n"); exit(1); }
    file_put_contents(getenv("UUPD_MANIFEST_PATH"), $out);
  ' UUPD_VERSION="$version" UUPD_ZIP_ID="$ZIP_ID" UUPD_SHA="$sha256" UUPD_MANIFEST_PATH="$drive_manifest" \
  || die "Failed writing manifest"

  ok "Drive manifest updated"
  if [[ -n "$MANIFEST_ID" ]]; then
    echo "  Manifest URL : https://drive.google.com/uc?export=download&id=$MANIFEST_ID"
  else
    echo "  Manifest URL : (set GDRIVE_MANIFEST_FILE_ID in cfg to print the direct link)"
  fi
  if [[ -n "$package_url" ]]; then
    echo "  Package  URL : $package_url"
  else
    echo "  Package  URL : (set GDRIVE_ZIP_FILE_ID in cfg to finalize this URL)"
  fi
  echo "  Note: ensure folder/files allow 'Anyone with the link - Viewer'."
}


# =====================================================
# ORCHESTRATE TARGETS
# =====================================================
# Normalize comma-separated list: "local,github,drive" -> "local github drive"
_targets="$(echo "${DEPLOY_TARGETS:-}" | tr ',' ' ' | tr -s ' ')"

if [[ -z "$_targets" ]]; then
  echo "[INFO] No DEPLOY_TARGETS set. Skipping deployment."
else
  echo "[INFO] Deploy targets: $_targets"
  for target in $_targets; do
    case "$target" in
      local)  deploy_local ;;
      github) deploy_github ;;
      drive)  deploy_drive ;;
      *)      die "Unknown deploy target: $target" ;;
    esac
  done
fi

echo
echo "[OK] Deployment complete: $DEPLOY_TARGETS"
sleep 2
