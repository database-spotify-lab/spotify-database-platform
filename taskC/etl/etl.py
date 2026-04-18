import pandas as pd
from pathlib import Path
import hashlib

BASE = Path("/Users/wmhjoy/Desktop/UR/Databases/project/milestone_3/spotify-database-platform")
INPUT_CSV = BASE / "spotify_top_10000.csv"
OUTPUT_DIR = BASE / "taskC" / "etl" / "output"
OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

df = pd.read_csv(INPUT_CSV).fillna("")

# Keep rows with minimum required fields
df = df[
    (df["Track Name"].astype(str).str.strip() != "") &
    (df["Artist Name(s)"].astype(str).str.strip() != "") &
    (df["Album Name"].astype(str).str.strip() != "")
].copy()

DEFAULT_STATUS = "approved"
DEFAULT_SUBMITTED_BY = 2
DEFAULT_REVIEWED_BY = 1


def strip_prefix(value: str, prefix: str) -> str:
    value = str(value).strip()
    if value.startswith(prefix):
        return value[len(prefix):]
    return value


def split_and_strip(text: str):
    return [x.strip() for x in str(text).split(",") if x.strip()]


def stable_hash(*parts, length=12):
    raw = "||".join(str(p).strip() for p in parts)
    return hashlib.md5(raw.encode("utf-8")).hexdigest()[:length]


def normalize_track_id(row) -> str:
    uri = str(row["Track URI"]).strip()
    if uri:
        return strip_prefix(uri, "spotify:track:")
    return f"track_missing_{stable_hash(row['Track Name'], row['Artist Name(s)'], row['Album Name'])}"


def normalize_album_id(row) -> str:
    uri = str(row["Album URI"]).strip()
    if uri:
        return strip_prefix(uri, "spotify:album:")
    return f"album_missing_{stable_hash(row['Album Name'], row['Album Release Date'], row['Album Artist Name(s)'])}"


def normalize_artist_id(uri: str, name: str) -> str:
    uri = str(uri).strip()
    name = str(name).strip()
    if uri:
        return strip_prefix(uri, "spotify:artist:")
    return f"artist_missing_{stable_hash(name)}"


def align_artists(uri_text: str, name_text: str):
    uris = split_and_strip(uri_text)
    names = split_and_strip(name_text)

    if not names and not uris:
        return []

    max_len = max(len(uris), len(names))
    out = []

    for i in range(max_len):
        uri = uris[i] if i < len(uris) else ""
        name = names[i] if i < len(names) else ""

        if not name and uri:
            name = f"Unknown Artist {i+1}"
        if name or uri:
            out.append({
                "artist_id": normalize_artist_id(uri, name),
                "artist_name": name if name else "Unknown Artist"
            })
    return out


def map_genre(raw_genre: str) -> str:
    g = str(raw_genre).strip().lower()
    if not g:
        return "Other"

    if any(k in g for k in ["hip hop", "hip-hop", "rap", "trap", "drill"]):
        return "Hip-Hop"
    if any(k in g for k in ["r&b", "soul", "neo soul", "motown"]):
        return "R&B"
    if any(k in g for k in ["rock", "metal", "punk", "grunge", "indie rock", "hard rock"]):
        return "Rock"
    if any(k in g for k in ["pop", "dance pop", "synthpop", "electropop", "k-pop", "j-pop"]):
        return "Pop"
    if any(k in g for k in ["electronic", "edm", "house", "techno", "trance", "dubstep", "indietronica"]):
        return "Electronic"
    if any(k in g for k in ["jazz", "blues", "swing", "bebop"]):
        return "Jazz"
    if any(k in g for k in ["classical", "orchestra", "opera", "baroque", "romantic era"]):
        return "Classical"
    if any(k in g for k in ["country", "bluegrass", "americana"]):
        return "Country"
    return "Other"


# --------------------------------------------------
# Precompute normalized ids on source df
# --------------------------------------------------
df["norm_track_id"] = df.apply(normalize_track_id, axis=1)
df["norm_album_id"] = df.apply(normalize_album_id, axis=1)


# --------------------------------------------------
# ARTISTS
# collect from both track artists and album artists
# --------------------------------------------------
artist_rows = []

for _, row in df.iterrows():
    track_pairs = align_artists(row["Artist URI(s)"], row["Artist Name(s)"])
    album_pairs = align_artists(row["Album Artist URI(s)"], row["Album Artist Name(s)"])

    for p in track_pairs:
        artist_rows.append(p)

    for p in album_pairs:
        artist_rows.append(p)

artists = pd.DataFrame(artist_rows).drop_duplicates(subset=["artist_id"]).reset_index(drop=True)
artists["status"] = DEFAULT_STATUS
artists["submitted_by"] = DEFAULT_SUBMITTED_BY
artists["reviewed_by"] = DEFAULT_REVIEWED_BY


# --------------------------------------------------
# ALBUMS
# --------------------------------------------------
albums = df[["norm_album_id", "Album Name", "Album Release Date", "Album Image URL"]].copy()
albums = albums.rename(columns={
    "norm_album_id": "album_id",
    "Album Name": "album_name",
    "Album Release Date": "release_date",
    "Album Image URL": "album_image_url",
})
albums = albums.drop_duplicates(subset=["album_id"]).reset_index(drop=True)
albums["status"] = DEFAULT_STATUS
albums["submitted_by"] = DEFAULT_SUBMITTED_BY
albums["reviewed_by"] = DEFAULT_REVIEWED_BY


# --------------------------------------------------
# TRACKS
# --------------------------------------------------
tracks = df[[
    "norm_track_id", "Track Name", "Popularity", "Track Duration (ms)",
    "Explicit", "Track Preview URL"
]].copy()

tracks = tracks.rename(columns={
    "norm_track_id": "track_id",
    "Track Name": "track_name",
    "Track Duration (ms)": "duration_ms",
    "Track Preview URL": "preview_url"
})

tracks["popularity"] = pd.to_numeric(tracks["Popularity"], errors="coerce").fillna(0).astype(int)
tracks["duration_ms"] = pd.to_numeric(tracks["duration_ms"], errors="coerce").fillna(0).astype(int)
tracks["explicit"] = tracks["Explicit"].astype(str).str.lower().map({"true": 1, "false": 0}).fillna(0).astype(int)

tracks = tracks[["track_id", "track_name", "popularity", "duration_ms", "explicit", "preview_url"]]
tracks = tracks.drop_duplicates(subset=["track_id"]).reset_index(drop=True)
tracks["status"] = DEFAULT_STATUS
tracks["submitted_by"] = DEFAULT_SUBMITTED_BY
tracks["reviewed_by"] = DEFAULT_REVIEWED_BY


# --------------------------------------------------
# TRACK_ARTISTS
# --------------------------------------------------
track_artists_rows = []

for _, row in df.iterrows():
    track_id = row["norm_track_id"]
    pairs = align_artists(row["Artist URI(s)"], row["Artist Name(s)"])

    for p in pairs:
        track_artists_rows.append({
            "track_id": track_id,
            "artist_id": p["artist_id"]
        })

track_artists = pd.DataFrame(track_artists_rows).drop_duplicates().reset_index(drop=True)


# --------------------------------------------------
# ALBUM_ARTISTS
# Prefer Album Artist columns; fallback to Artist columns
# --------------------------------------------------
album_artists_rows = []

for _, row in df.iterrows():
    album_id = row["norm_album_id"]

    album_pairs = align_artists(row["Album Artist URI(s)"], row["Album Artist Name(s)"])
    if not album_pairs:
        album_pairs = align_artists(row["Artist URI(s)"], row["Artist Name(s)"])

    for p in album_pairs:
        album_artists_rows.append({
            "album_id": album_id,
            "artist_id": p["artist_id"]
        })

album_artists = pd.DataFrame(album_artists_rows).drop_duplicates().reset_index(drop=True)


# --------------------------------------------------
# ALBUM_TRACKS
# --------------------------------------------------
album_tracks = df[["norm_album_id", "norm_track_id", "Disc Number", "Track Number"]].copy()
album_tracks = album_tracks.rename(columns={
    "norm_album_id": "album_id",
    "norm_track_id": "track_id",
    "Disc Number": "disc_number",
    "Track Number": "track_number"
})
album_tracks["disc_number"] = pd.to_numeric(album_tracks["disc_number"], errors="coerce").fillna(1).astype(int)
album_tracks["track_number"] = pd.to_numeric(album_tracks["track_number"], errors="coerce").fillna(1).astype(int)
album_tracks = album_tracks[["album_id", "track_id", "disc_number", "track_number"]].drop_duplicates().reset_index(drop=True)


# --------------------------------------------------
# ARTIST_GENRES (raw)
# --------------------------------------------------
artist_genres_rows = []

for _, row in df.iterrows():
    pairs = align_artists(row["Artist URI(s)"], row["Artist Name(s)"])
    genres = split_and_strip(row["Artist Genres"])

    for p in pairs:
        for genre in genres:
            artist_genres_rows.append({
                "artist_id": p["artist_id"],
                "genre": genre
            })

artist_genres = pd.DataFrame(artist_genres_rows).drop_duplicates().reset_index(drop=True)


# --------------------------------------------------
# ARTIST_GENRE_CATEGORIES (helper only)
# --------------------------------------------------
artist_genre_categories = artist_genres.copy()
artist_genre_categories["genre_category"] = artist_genre_categories["genre"].apply(map_genre)
artist_genre_categories = artist_genre_categories[["artist_id", "genre_category"]].drop_duplicates().reset_index(drop=True)


# --------------------------------------------------
# AUDIO_FEATURES
# --------------------------------------------------
audio = df.drop_duplicates(subset=["norm_track_id"]).copy()

audio_features = pd.DataFrame({
    "track_id": audio["norm_track_id"],
    "danceability": pd.to_numeric(audio["Danceability"], errors="coerce"),
    "energy": pd.to_numeric(audio["Energy"], errors="coerce"),
    "valence": pd.to_numeric(audio["Valence"], errors="coerce"),
    "acousticness": pd.to_numeric(audio["Acousticness"], errors="coerce"),
    "instrumentalness": pd.to_numeric(audio["Instrumentalness"], errors="coerce"),
    "liveness": pd.to_numeric(audio["Liveness"], errors="coerce"),
    "speechiness": pd.to_numeric(audio["Speechiness"], errors="coerce"),
    "tempo": pd.to_numeric(audio["Tempo"], errors="coerce"),
    "key": pd.to_numeric(audio["Key"], errors="coerce"),
    "mode": pd.to_numeric(audio["Mode"], errors="coerce"),
    "loudness": pd.to_numeric(audio["Loudness"], errors="coerce"),
    "time_signature": pd.to_numeric(audio["Time Signature"], errors="coerce"),
}).drop_duplicates(subset=["track_id"]).reset_index(drop=True)


# --------------------------------------------------
# Save outputs
# --------------------------------------------------
artists.to_csv(OUTPUT_DIR / "artists.csv", index=False)
albums.to_csv(OUTPUT_DIR / "albums.csv", index=False)
tracks.to_csv(OUTPUT_DIR / "tracks.csv", index=False)
track_artists.to_csv(OUTPUT_DIR / "track_artists.csv", index=False)
album_artists.to_csv(OUTPUT_DIR / "album_artists.csv", index=False)
album_tracks.to_csv(OUTPUT_DIR / "album_tracks.csv", index=False)
artist_genres.to_csv(OUTPUT_DIR / "artist_genres.csv", index=False)
artist_genre_categories.to_csv(OUTPUT_DIR / "artist_genre_categories.csv", index=False)
audio_features.to_csv(OUTPUT_DIR / "audio_features.csv", index=False)

print("ETL completed.")
print("Rows generated:")
print("artists:", len(artists))
print("albums:", len(albums))
print("tracks:", len(tracks))
print("track_artists:", len(track_artists))
print("album_artists:", len(album_artists))
print("album_tracks:", len(album_tracks))
print("artist_genres:", len(artist_genres))
print("artist_genre_categories:", len(artist_genre_categories))
print("audio_features:", len(audio_features))