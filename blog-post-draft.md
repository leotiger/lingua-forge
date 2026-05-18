# From a Handful of Messy Files to a Plugin Anyone Can Use — The Story Behind Lingua Forge

*A personal note about why this plugin exists, what it actually cost us, and why we think it matters beyond the code.*

---

## It started with necessity — as most things worth doing do

A few weeks ago we were in the middle of building a new website. Not as a side project. Out of necessity — which, if you stop to think about it, is still one of the most powerful forces humans have. The site is [cal-talaia.cat](https://cal-talaia.cat), still incomplete, still being shaped, still serving as our live test environment for everything described in this post. A rural property in Catalonia that needed to speak to visitors in Catalan, Spanish, English, and German, all at once, without a multilingual plugin subscription eating into margins before a single booking arrived.

So we did what you do when necessity is pressing: we wrote some procedural PHP, dropped it into WordPress's `mu-plugins` folder — a special place that loads code automatically, before anything else, without asking — and moved on to the next problem.

For a while that was fine. The language routing worked. Pages loaded in the right language. The hreflang tags made search engines happy. But the code was growing in a way that code grows when nobody planned for it to grow: sideways, acquiring dependencies on itself, accumulating assumptions about the specific server it lived on. It worked perfectly on *our* setup. It would have baffled anyone else. And it would eventually baffle us, given enough time.

That is the thing about code written for a controlled environment. It solves your problem. It does not solve anyone else's.

---

## Weeks, not years — but it felt longer

We want to be honest about the timeline because it matters for understanding the pace of what is possible now. This was not a years-long open-source project with a team of contributors and a roadmap committee. We refactored a collection of mu-plugin scripts into a full WordPress plugin — with namespaced PHP classes, a unified settings page, a translation memory, a glossary, WP-CLI commands, AI behavior presets, a side-by-side diff preview, an API usage tracker — in a matter of weeks.

That is remarkable and slightly alarming in equal measure. The speed was only possible because we had AI assistance throughout. But speed without quality is just fast failure, so let us talk honestly about what that assistance actually looked like.

---

## The AI chapter — including the part that costs money and dignity

We started with ChatGPT. That matters, because tool selection matters, and the choice of which AI to use for which task is not trivial. ChatGPT got us moving. At a certain point we moved to Claude. Different model, different strengths, different failure modes, different way of reasoning through a PHP namespace problem at eleven in the evening. Neither is universally better. Both required supervision.

And this is the part we want to be genuinely honest about, because the current conversation around AI tends toward one of two overcorrections: either AI is going to replace all human work, or it is an overhyped autocomplete. The reality we lived through these past weeks is more interesting and more humbling than either.

AI is extraordinarily good at automation. Give it a pattern and ask it to apply that pattern consistently across fifty files, and it will do so without complaint, without coffee, and — if you have written the instruction clearly — without error. It is also good at surfacing things you forgot to think about, at explaining why something broke, at drafting documentation that would have taken you an hour and takes it thirty seconds.

But here is what it cannot do, or at least cannot do yet, reliably: *create something genuinely different*. Not without you. The token count accumulated over this project would make your eyes water — we will not publish it, partly out of embarrassment and partly because the exact number would distract from the point. Those tokens bought us capability, speed, and consistency. What they did not buy us was judgment. Every time the AI produced a beautifully structured function that quietly violated a WordPress.org plugin review rule, a human had to catch it. Every time it introduced a PHP namespace without auditing every class reference below it — which, if you are curious, triggers a fatal error on a live site in the most inopportune way imaginable — a human had to fix it.

What the AI does is process vast amounts of data at a scale no individual human could match, and compress that into something that looks like knowledge. What it produces still needs someone who understands what they actually want, who can tell the difference between a solution and a solution to the wrong problem, and who knows when to push back. The human counterpart in this collaboration was not a passive prompter. The human was the one who kept the thing on course.

AI proposes. Humans dispose. That is also, not coincidentally, exactly the philosophy baked into Lingua Forge itself: nothing is applied automatically, every AI result sits in a review panel, and you decide what goes into your content.

---

## What was actually built

Lingua Forge 1.2.0 is a single WordPress plugin that handles the three concerns that always end up tangled together on a multilingual site:

**Getting visitors to the right language.** URL prefixes like `/de/` or `/fr/`, hreflang tags for search engines, a language switcher block for the modern block editor, and a panel that warns you when a translation has fallen behind its source post. There is also a tool that scans translated pages for internal links pointing to the wrong language version and fixes them in bulk — a quiet feature that saves hours of manual checking.

**Keeping SEO accurate across languages.** A meta description field on every post and page, with a character counter, an AI-generation button, and a fallback chain that ensures something sensible is always output even if the field is left blank.

**Helping editors work faster.** Translate a full page in one click, with all the block formatting intact. Generate a meta description or excerpt without leaving the editor. Keep a glossary of terms — brand names, technical vocabulary, phrases that must never be paraphrased — that is injected into every translation prompt automatically. Track exactly how many tokens each feature consumed, by provider, by date, so the API bill holds no surprises.

All of it, free. One plugin. No annual license. No proprietary credit system sitting between you and your AI provider. If you want AI features, you connect your own API key and pay the provider directly — usually a few cents per post. If you prefer to translate manually, everything works just as well at no cost at all.

---

## Why this matters — beyond the feature list

Multilingual websites are not exotic. They are the daily reality of small businesses serving more than one region, of cultural organisations trying to reach communities in their own language, of nonprofits publishing information that people need to understand clearly, of rural accommodations in Catalonia that want a German guest to feel as welcome as a local one.

The existing tools for this — and they are good tools, built by serious people — arrived with annual license fees that compound quietly over years. A small site paying €99 to €199 per year for multilingual infrastructure, on top of hosting and everything else, is not an abstract number. It is a decision that some operators simply cannot make, which means their content stays in one language, which means fewer people can read it. That is a loss, and it is a structural one produced by the way the market decided to price something that should arguably be considered infrastructure.

There is also a second layer: the AI translation features in those tools were delivered through proprietary credit systems that stood between the operator and the AI provider. You paid for credits, the credits bought words, the rate was set by the intermediary. Lingua Forge removes that layer entirely. Your API key, your provider, the rate they publish, nothing in between.

We are not naive about economics. Software takes time to build and money to maintain. But there is a point at which the number of intermediaries between a person with something to say and the people they want to say it to stops being a business model and starts being a problem. Open-source software exists precisely to push that point back — to insist that some things belong to everyone.

Lingua Forge is our contribution to that. The code is GPL-2.0 or higher. It will remain free.

---

## A note for the reader who skipped the technical parts

If the references to PHP namespaces, WP-CLI commands, and plugin review rules left you somewhere between confused and indifferent, that is entirely fine — none of that is your concern. Your concern is: you have a website, you want it to speak more than one language, and you would rather not pay a subscription indefinitely for the privilege of doing so.

Lingua Forge installs like any other WordPress plugin. You activate it, go to Settings, pick your languages, and start working. The AI features are there when you want them and completely invisible when you do not. Everything was designed so that the complexity stays out of your way and the editor experience stays simple.

[Cal Talaia](https://cal-talaia.cat) is still being built — it is a working site, not a showcase, which means it reflects the real state of things rather than a curated demo. That felt appropriate. The plugin was built in the same spirit: for real use, on a real site, under real constraints, by people who needed it to work.

That is the only origin story that produces something genuinely useful.

---

*Lingua Forge is free and open-source. Source code and issue tracker: [github.com/leotiger/lingua-forge](https://github.com/leotiger/lingua-forge)*
