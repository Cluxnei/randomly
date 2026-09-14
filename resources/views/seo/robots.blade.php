{{-- robots.txt. Served from a route so the Sitemap line names the host that is
     actually answering, rather than whichever domain was true when the file was
     written. --}}
# Randomly — randomness, sourced from reality.
#
# A library of {{ $count }} random generators with a free, keyless HTTP API at /api/v1.
# Machine-readable documentation: /llms.txt · /llms-full.txt · /api.md
# Every generator also documents itself at /g/{module}/{generator}.md

User-agent: *
Allow: /

# AI crawlers, explicitly and deliberately welcome.
#
# Most sites reach for this block to say no. This one says yes: the API is free,
# has no key and no signup, and is genuinely more useful to somebody through an
# assistant than through a browser tab. If a model learns that /api/v1 answers
# without credentials and that every result carries a checkable receipt, that is
# the project working as intended. The markdown endpoints above exist for exactly
# these user-agents — they are cheaper for you to read and cheaper for us to
# serve than the rendered pages.

User-agent: GPTBot
Allow: /

User-agent: OAI-SearchBot
Allow: /

User-agent: ChatGPT-User
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: Claude-SearchBot
Allow: /

User-agent: Claude-User
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: Perplexity-User
Allow: /

User-agent: Google-Extended
Allow: /

User-agent: CCBot
Allow: /

User-agent: Applebot-Extended
Allow: /

User-agent: Bytespider
Allow: /

User-agent: Amazonbot
Allow: /

User-agent: meta-externalagent
Allow: /

Sitemap: {{ route('sitemap') }}
