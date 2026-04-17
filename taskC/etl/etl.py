import pandas as pd
from pathlib import Path

BASE = Path("/Users/wmhjoy/Desktop/UR/Databases/project/milestone_3/spotify-database-platform")
INPUT_CSV = BASE / "spotify_top_10000.csv"
OUTPUT_DIR = BASE / "taskC" / "etl" / "output"
OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

df = pd.read_csv(INPUT_CSV)

# -----------------------------
# Basic cleanup
# -----------------------------
df = df.fillna("")

# Keep only rows with minimum required fields
df = df[(df["Track Name"] != "") & (df["Artist Name(s)"] != "") & (df["Album Name"] != "")].copy()

# Add system workflow defaults
DEFAULT_STATUS = "approved"
DEFAULT_SUBMITTED_BY = 2
DEFAULT_REVIEWED_BY = 1

# -----------------------------
# ARTISTS
# -----------------------------
artist_names = (
    df["Artist Name(s)"]
    .astype(str)
    .str.split(",")
    .explode()
    .str.strip()
)
artist_names = artist_names[artist_names != ""].drop_duplicates().reset_index(drop=True)

artists = pd.DataFrame({
    "artist_id": [f"a{i+1}" for i in range(len(artist_names))],
    "artist_name": artist_names,
})
artists["status"] = DEFAULT_STATUS
artists["submitted_by"] = DEFAULT_SUBMITTED_BY
artists["reviewed_by"] = DEFAULT_REVIEWED_BY

artist_map = dict(zip(artists["artist_name"], artists["artist_id"]))

# -----------------------------
# ALBUMS
# Use album name + release date as distinct key
# -----------------------------
albums_raw = df[["Album Name", "Album Release Date", "Album Image URL"]].drop_duplicates().reset_index(drop=True)
albums = pd.DataFrame({
    "album_id": [f"al{i+1}" for i in range(len(albums_raw))],
    "album_name": albums_raw["Album Name"],
    "release_date": albums_raw["Album Release Date"],
    "album_image_url": albums_raw["Album Image URL"],
})
albums["status"] = DEFAULT_STATUS
albums["submitted_by"] = DEFAULT_SUBMITTED_BY
albums["reviewed_by"] = DEFAULT_REVIEWED_BY

album_key = list(zip(albums["album_name"], albums["release_date"], albums["album_image_url"]))
album_map = {k: v for k, v in zip(album_key, albums["album_id"])}

# -----------------------------
# TRACKS
# Use Track URI if available, otherwise fallback to Track Name
# -----------------------------
tracks_raw = df[[
    "Track URI", "Track Name", "Popularity", "Track Duration (ms)",
    "Explicit", "Track Preview URL"
]].drop_duplicates(subset=["Track URI"]).reset_index(drop=True)

tracks = pd.DataFrame({
    "track_id": [f"t{i+1}" for i in range(len(tracks_raw))],
    "track_name": tracks_raw["Track Name"],
    "popularity": pd.to_numeric(tracks_raw["Popularity"], errors="coerce").fillna(0).astype(int),
    "duration_ms": pd.to_numeric(tracks_raw["Track Duration (ms)"], errors="coerce").fillna(0).astype(int),
    "explicit": tracks_raw["Explicit"].astype(str).str.lower().map({"true": 1, "false": 0}).fillna(0).astype(int),
    "preview_url": tracks_raw["Track Preview URL"],
})
tracks["status"] = DEFAULT_STATUS
tracks["submitted_by"] = DEFAULT_SUBMITTED_BY
tracks["reviewed_by"] = DEFAULT_REVIEWED_BY

track_map = dict(zip(tracks_raw["Track URI"], tracks["track_id"]))

# -----------------------------
# TRACK_ARTISTS
# -----------------------------
track_artists_rows = []
for _, row in df.iterrows():
    track_uri = row["Track URI"]
    if track_uri not in track_map:
        continue
    track_id = track_map[track_uri]
    for artist in str(row["Artist Name(s)"]).split(","):
        artist = artist.strip()
        if artist and artist in artist_map:
            track_artists_rows.append((track_id, artist_map[artist]))

track_artists = pd.DataFrame(track_artists_rows, columns=["track_id", "artist_id"]).drop_duplicates()

# -----------------------------
# ALBUM_ARTISTS
# -----------------------------
album_artists_rows = []
for _, row in df.iterrows():
    k = (row["Album Name"], row["Album Release Date"], row["Album Image URL"])
    if k not in album_map:
        continue
    album_id = album_map[k]
    for artist in str(row["Album Artist Name(s)"]).split(","):
        artist = artist.strip()
        if artist and artist in artist_map:
            album_artists_rows.append((album_id, artist_map[artist]))

album_artists = pd.DataFrame(album_artists_rows, columns=["album_id", "artist_id"]).drop_duplicates()

# -----------------------------
# ALBUM_TRACKS
# -----------------------------
album_tracks_rows = []
for _, row in df.iterrows():
    k = (row["Album Name"], row["Album Release Date"], row["Album Image URL"])
    track_uri = row["Track URI"]
    if k not in album_map or track_uri not in track_map:
        continue
    album_tracks_rows.append((
        album_map[k],
        track_map[track_uri],
        int(pd.to_numeric(row["Disc Number"], errors="coerce")) if str(row["Disc Number"]).strip() != "" else 1,
        int(pd.to_numeric(row["Track Number"], errors="coerce")) if str(row["Track Number"]).strip() != "" else 1,
    ))

album_tracks = pd.DataFrame(album_tracks_rows, columns=["album_id", "track_id", "disc_number", "track_number"]).drop_duplicates()

# -----------------------------
# ARTIST_GENRES
# -----------------------------
artist_genres_rows = []
for _, row in df.iterrows():
    artist_names = [a.strip() for a in str(row["Artist Name(s)"]).split(",") if a.strip()]
    genres = [g.strip() for g in str(row["Artist Genres"]).split(",") if g.strip()]
    for artist in artist_names:
        if artist in artist_map:
            for genre in genres:
                artist_genres_rows.append((artist_map[artist], genre))

artist_genres = pd.DataFrame(artist_genres_rows, columns=["artist_id", "genre"]).drop_duplicates()

# -----------------------------
# AUDIO_FEATURES
# -----------------------------
audio_rows = []
for _, row in df.drop_duplicates(subset=["Track URI"]).iterrows():
    track_uri = row["Track URI"]
    if track_uri not in track_map:
        continue
    audio_rows.append({
        "track_id": track_map[track_uri],
        "danceability": pd.to_numeric(row["Danceability"], errors="coerce"),
        "energy": pd.to_numeric(row["Energy"], errors="coerce"),
        "valence": pd.to_numeric(row["Valence"], errors="coerce"),
        "acousticness": pd.to_numeric(row["Acousticness"], errors="coerce"),
        "instrumentalness": pd.to_numeric(row["Instrumentalness"], errors="coerce"),
        "liveness": pd.to_numeric(row["Liveness"], errors="coerce"),
        "speechiness": pd.to_numeric(row["Speechiness"], errors="coerce"),
        "tempo": pd.to_numeric(row["Tempo"], errors="coerce"),
        "key": pd.to_numeric(row["Key"], errors="coerce"),
        "mode": pd.to_numeric(row["Mode"], errors="coerce"),
        "loudness": pd.to_numeric(row["Loudness"], errors="coerce"),
        "time_signature": pd.to_numeric(row["Time Signature"], errors="coerce"),
    })

audio_features = pd.DataFrame(audio_rows).drop_duplicates(subset=["track_id"])

# -----------------------------
# Save outputs
# -----------------------------
artists.to_csv(OUTPUT_DIR / "artists.csv", index=False)
albums.to_csv(OUTPUT_DIR / "albums.csv", index=False)
tracks.to_csv(OUTPUT_DIR / "tracks.csv", index=False)
track_artists.to_csv(OUTPUT_DIR / "track_artists.csv", index=False)
album_artists.to_csv(OUTPUT_DIR / "album_artists.csv", index=False)
album_tracks.to_csv(OUTPUT_DIR / "album_tracks.csv", index=False)
artist_genres.to_csv(OUTPUT_DIR / "artist_genres.csv", index=False)
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
print("audio_features:", len(audio_features))
