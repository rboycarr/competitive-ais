# Voice Command Assistant

## Scope and operating instructions

- Start with browser push-to-talk, speech-to-text, intent extraction and text confirmation.
- Add streaming voice later via supported realtime speech APIs or evaluated local ASR/TTS.
- Architecture: microphone -> transcription -> intent + entities -> RBAC policy -> typed Laravel tool -> confirmation for writes -> response -> optional speech.
- Support English and Filipino/Taglish with correction UI for names, SKUs, numbers and currency.
- Never execute destructive, financial, publishing or access-control changes from speech alone; require explicit on-screen confirmation and step-up authentication where warranted.
- Limit recording duration; consent and retention settings; avoid storing raw audio by default.
- Defend against replay, voice spoofing and audio prompt injection; voice is not an authentication factor.
- Include transcript preview, cancel, accessibility fallback, timeouts and complete action audit trail.
