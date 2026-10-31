# 06 — Voice Intelligence Assistant Design

| Field | Value |
|---|---|
| Status | Design baseline (Phase 0), 2026-10-31. **Implementation is Phase 5.** |
| Owner | Claude Code; Codex (confirmation-bypass and spoofing review) |
| Decision | Local STT/TTS only (D3). Push-to-talk first; realtime duplex (V2) only after V1 passes its gates. |
| Related | `skills/05_VOICE_SKILLS.md`, `docs/05` (tools), `docs/07` |

## 1. V1 pipeline (push-to-talk)

```
user presses and holds the mic button (explicit gesture; first use asks for mic consent)
 → browser MediaRecorder (Opus/WebM), max 60 s, visible recording indicator, cancel
 → upload over HTTPS (authenticated session, CSRF, rate-limited)
 → VoiceTranscribeJob → Python worker: local STT (faster-whisper or whisper.cpp; language auto or user-set en/fil)
 → transcript + per-segment confidence shown and editable  ← the user can fix names, SKUs, numbers, currency
 → intent + slots: (a) rule grammar for common intents; (b) local LLM with JSON-schema output as fallback
 → entity resolution against the registries (visibleTo-aware); ambiguities shown as choices
 → AccessScope + policy check (the same tools as docs/05; voice is never a separate permission path)
 → read intents: execute → render the answer (text) → optional TTS
 → write intents: create pending_action → on-screen preview of exact parameters → user CLICKS Confirm
      (step-up re-authentication for privileged actions) → execute → audit → result
 → audio discarded after transcription (default); transcript kept per retention setting
```

## 2. Intents (initial set)

| Intent | Example | Kind | Confirmation |
|---|---|---|---|
| `price_changes.query` | "Show price drops for competitor X this quarter" | read | none |
| `alerts.summarize` | "Summarize today's verified alerts" | read | none |
| `products.compare` | "Compare product A and product B" | read | none |
| `briefing.read` | "Read the weekly briefing" | read (TTS) | none |
| `field_observation.draft` | "Draft a field observation: brand Y costs 92 pesos per 500 grams" | write (draft) | **on-screen click** |
| `investigation.propose` | "Open an investigation on this alert" | write (draft) | **on-screen click** |
| publish / export / delete / notify / permission change | any | sensitive | **not available by voice in V1**; the voice assistant links to the normal UI flow instead |

Numbers and currency ("92 pesos per 500 grams") are parsed into structured slots (`amount=92`, `currency=PHP`, `pack_size=500`, `pack_unit=g`) and always shown back before any draft is saved.

## 3. Safety rules
- Voice is **never** an authentication factor and never bypasses permissions.
- A spoken "yes" never confirms a write. Confirmation is an explicit UI control, and the preview shows the exact action.
- Pending actions created by voice follow the binding and atomic-consumption rules in `docs/05` §5: bound to user, session, and voice session; re-authorized at execution.
- No always-on microphone. Listening happens only while the button is held; any future hands-free mode needs separate opt-in and an ADR.
- Replay and spoofing: uploads are bound to the session with a short-lived nonce, and a recording cannot be submitted twice (hash dedupe per session).
- Audio prompt injection: transcripts are treated as untrusted user input and pass through the same tool and argument validation as typed text.
- Every voice command, parsed intent, confirmation, and outcome is audited (`voice.*` actions), without storing audio.

## 4. Accessibility and fallback
- A text input sits next to the mic button with identical capabilities. Every voice feature is reachable by keyboard.
- Transcript review is mandatory for writes. Captions are shown for any TTS output.
- Visible states: recording, uploading, transcribing, needs clarification, ready to confirm, done, error.
- Low-bandwidth: the max recording length and compression keep uploads small, and the UI degrades to text if the upload fails.

## 5. Data retention

| Data | Default | Configurable |
|---|---|---|
| Raw audio | Not stored (deleted after transcription) | Opt-in storage with explicit retention, admin-controlled |
| Transcripts | 30 days (O8) | Yes |
| Parsed intent and audit record | Same as audit retention | No (audit) |

## 6. Languages
English, Filipino, and Taglish (code-switching). The STT model is evaluated on a synthetic test set (product names, numbers, peso amounts, units) before release. A local TTS voice is used only if quality is acceptable for en/fil; otherwise the answer is text only.

## 7. V2 (realtime duplex)
Only after V1 meets: authorization tests pass, confirmation cannot be bypassed (tests), p95 time from release to transcript is acceptable on the target hardware, and usability testing is done. Needs a separate ADR (streaming STT, barge-in, VAD) and a fresh security review.

## Risks
- Recognition errors with names and Taglish. Mitigation: a correction UI, registry-backed entity resolution, and disambiguation prompts.
- Hardware load if STT shares a GPU with the LLM. Mitigation: sizing in the Phase 4 spike (O4).

## Acceptance criteria (Phase 5 exit)
- Voice cannot perform any write without the UI confirmation (tests).
- Voice cannot access data the text UI would deny (shared authorization matrix rows).
- The audio-not-stored default is verified; transcript retention job tested.
