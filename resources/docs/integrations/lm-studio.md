---
title: LM Studio (local LLM)
section: Integrations
order: 110
updated: 2026-05-04
author: Aaron Reimann
tags: [integrations, ai, llm, local, lm-studio]
---

LM Studio runs a local OpenAI-compatible LLM endpoint on `http://localhost:1234`. We use it for nginx-log threat analysis. Critical property: **no data leaves the box.** The model runs on the operator's own hardware against logs that contain real visitor data.

## Why we use it

Sending raw nginx access logs to an external API would mean shipping IPs, request paths, query strings, user agents — much of it personally identifiable in aggregate — to a third-party AI provider. That's a non-starter for an agency that handles client data. LM Studio gives us a model running on local hardware over loopback, with the same OpenAI-compatible API shape so we can swap models without touching app code.

## Setup

1. Install LM Studio (`lmstudio.ai`).
2. Download a quantized instruction-tuned model. Recommended: Llama 3 8B Instruct or Mistral 7B v0.2 Instruct (Q4_K_M is the sweet spot on Apple Silicon).
3. In LM Studio: Local Server tab → Start Server. Default port 1234.
4. Set in `.env` (defaults work if you didn't change anything):

   ```
   CLOCKWORK_LM_STUDIO_BASE_URL=http://localhost:1234/v1
   CLOCKWORK_LM_STUDIO_MODEL=meta-llama-3-8b-instruct
   CLOCKWORK_LM_STUDIO_API_KEY=lm-studio
   CLOCKWORK_LM_STUDIO_TIMEOUT=120
   ```

5. Verify the server is up:

```bash
curl http://localhost:1234/v1/models
```

Should return a JSON list with the loaded model.

## Auth

The OpenAI-compatible spec requires an `Authorization: Bearer` header. LM Studio doesn't actually enforce it (loopback only), but keep something there — `lm-studio` is fine — so the SDK doesn't complain.

## Endpoints we call

Standard OpenAI Chat Completions:

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/models` | Verify model is loaded. |
| POST | `/v1/chat/completions` | Score a batch of nginx log entries. |

## Files

- `config/clockwork.php` → `lm_studio` key (base URL, model, key, timeout).
- The analyzer service that calls it lives alongside the ingest commands and is invoked from the threat-classification path.

## Operational notes

- **Loopback only.** Never expose port 1234 beyond localhost. There's no auth and the model can produce sensitive content from the log data you feed it.
- **Models are pluggable.** As long as the model is OpenAI-compatible, just change `CLOCKWORK_LM_STUDIO_MODEL`. We've used Llama 3 8B and Mistral 7B v0.2 Instruct without code changes.
- **Timeouts can be long.** Cold-start on a small model is ~10s; a batch of 50 log entries can be 30–60s on a Mac mini. The default 120s timeout has plenty of headroom.
- **Quantization quality matters.** Q4_K_M is the practical sweet spot — smaller variants (Q3 etc.) hallucinate verdicts more often. Q8/full precision are slower without meaningfully better classification.

## Gotchas

- **LM Studio's Local Server stops when you close the app.** If the analyzer is failing with connection refused, check that the server tab is running.
- **Model loaded ≠ model in your env var.** LM Studio might have a different model loaded than `CLOCKWORK_LM_STUDIO_MODEL` claims. The first response will tell you.
- **No streaming.** Our analyzer uses synchronous calls. Large batches block the PHP process for the duration of the call. Batch size matters.
