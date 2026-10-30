# C2.5 checker probes (23 Oct 2026)

These are the checker session's own probe scripts. They contain no outside code. Run them with Herd PHP 8.4 from a worktree that has a copied `vendor/`. For a Vorbis input, use ffmpeg 8.1.1:

    ffmpeg -f lavfi -i "sine=frequency=170:sample_rate=48000:duration=200" -ac 1 -c:a libvorbis -q:a 0 vorbis-200s.ogg

- `vorbis-understated.php <worktree> <in.ogg> <out.ogg> <ffprobe>`: scales the page positions down to the checker's floor and recomputes the CRCs (F1). Today it gives: checker ready, 25,000 ms; ffprobe too long.
- `vorbis-verdicts.php <worktree> <ffprobe> <files...>`: prints the checker's verdict and `inspect()` with ffprobe, and reports whether it throws (F2: an honest 600 s file throws).
- `combined-timing.php <worktree> 350000 400000 450000`: tiny blocks plus one bad padding packet (F3). Today it takes 2.07–2.27 s.
- `padding-timing.php <worktree> <MiB>`: one bad padding packet on its own.
- `mp4-understated.php <worktree> <in.m4a> <out.m4a> <ffprobe>`: divides the AAC timing by 8. The checker and ffprobe are both fooled the same way, so it is not a finding.
- `mp4-many-mdat.php <worktree> mdat 100000 300000`: 100,000 one-byte chunks in the last of 300,000 `mdat` boxes (F5). It takes 4.5–5.1 s and prints peak memory.
- `mp4-many-mdat.php <worktree> free 900000`: a `moov` holding 900,000 empty `free` boxes (F6). It comes back ready and peaks at 280 MiB.
