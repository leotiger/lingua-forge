# Knocking on a Door with No Window

*On giving something away for free, getting flagged by a classifier that detected something "potential", and finding no human willing to say what it actually found.*

---

I built a plugin. Spent weeks on it. Gave it a name.

Then I tried to give it away.

That last part, it turns out, is the hard part.

---

## The gift economy of open source

The WordPress plugin directory is one of the better ideas the internet has produced. Developers publish their work there, for free, and anyone running a WordPress site can install it in two clicks. No marketplace fee. No subscription. No middleman. The transaction is: I made this, you can have it, the only currency is usefulness.

I built Lingua Forge — a multilingual routing and AI editorial plugin — out of necessity. I needed it for a real website. When it was done I thought: other people probably need this too. So I submitted it to the directory to give it away.

The process began.

---

## The door

I've been imagining the WordPress.org plugin review process as a door.

It's a heavy door. Behind it, people are working — reviewing hundreds of submissions, catching security problems, enforcing naming standards, keeping the directory from becoming a wasteland of abandoned plugins with misleading descriptions. That work matters. I'm not here to dismiss it.

But from the outside, the door has no window. You knock. Something happens behind it. A message comes back. You reply. Another message comes back. At no point do you know exactly who you're talking to, what information they have, or what would actually satisfy them.

My first message back told me that Lingua Forge may conflict with an existing project or brand.

Not which project. Not which brand. Not how a user would plausibly confuse my free multilingual WordPress plugin with whatever was on the other side of that concern. Just: rename it.

---

## The algorithm with a badge

The first review message was flagged as potentially automated, and it showed. Here's the line that matters:

> *"The AI has detected ✨ 'Lingua Forge' as potential trademark(s)."*

Potential. A possibility. Not a confirmed trademark, not a named project — a fuzzy match raised by a classifier running against an undisclosed dataset. That's fine as a starting point. What's not fine is what came next.

I replied with a detailed explanation of the name's origin, noted that no WordPress.org plugin uses it, pointed to the public GitHub repository and the live site that predate my submission. The second reply, from a human reviewer, maintained the position and added nothing. No project named. No link. No explanation of what confusion anyone would actually experience. The AI had detected something potential and a human had made it permanent.

Which brings me to how AI is supposed to work.

A classifier points at something. A human then looks — actually looks, with access to the specific information the tool flagged — and decides whether the concern is real. If it is, the human adds what the classifier cannot: which project, what the actual overlap is, why it matters. The classifier narrows the space. The human is supposed to do something with that.

What I got instead was the flag with a signature on it.

I've spent the last few weeks building a plugin whose central design principle is that AI results always land in a review panel, nothing is applied automatically, and a human decides what happens next. The WordPress.org review process, it turns out, has that same principle written somewhere and a somewhat different implementation in practice.

---

## What I know and what I don't

I searched the WordPress.org directory before choosing the name and found nothing. I registered the GitHub repository under it before submitting. I built a live website around it. The name is mine in every practical sense available to someone who hasn't filed a trademark.

What I don't know: which project the review team thinks I'm conflicting with. Whether anyone has actually looked at both things and compared them. Whether the flag came from a classifier that nobody on the review team independently verified.

I've asked twice. No answer.

---

## The irony

I built a plugin that uses AI to help people write and translate content. The whole point is that AI assists but doesn't replace judgment — results sit in a panel, you review them, you decide, because AI gets things wrong and someone who understands what they want is the only reliable check on that.

I submitted this plugin to a directory. The directory used AI to flag the name. A human transmitted the flag without checking whether it was right.

The plugin embodies a principle. The process that rejected it skipped the same principle.

I'll spare the grand argument about AI safety. The observation is smaller and more boring: classifiers produce false positives. That's not a criticism — it's a property of the technology. The entire reason you put a human in the loop is to catch them. If the human just signs off on whatever the classifier says, you haven't added oversight. You've added a delay.

---

## A note on the name

Lingua Forge.

A name I like. *Lingua* — language, from the Latin. *Forge* — to make, to shape, the kind of making that takes heat and intention. It carries what the plugin does in two syllables.

Apparently too close to something. I don't know what. Nobody will say.

If I rename it I won't be doing so because the concern is valid. I'll be doing so because the door is closed, the window is missing, and I have a website that needs a multilingual plugin. At some point the pragmatic answer is to pick a new name, submit again, and save the argument for a letter to Matt.

Which I have also written.

---

## What would actually help

I'm not asking for the keys to the building. Just for someone to open the door wide enough to tell me which room the problem is in.

Name the project. One sentence. If the concern is real, I'll evaluate it and act accordingly. If it's a classifier false positive — a pattern match that no human ever verified against an actual product — then someone should know that, because there are probably other developers at the same door right now, not knowing what they did wrong.

Good free plugins don't grow on trees, and unexplained friction is a quiet way of discouraging the people who build them.

I still want to give this one away. I just need to know what to call it first.

---

## Meanwhile

While that conversation continues — or doesn't — there's a perfectly good plugin sitting in a repository doing nothing useful for anyone.

So starting today, Lingua Forge is available directly from this website. No directory. No classifier. No door. You download it, read what it does, decide whether you want it. People are capable of making their own decisions about software without a directory's blessing.

This is, of course, how software worked before there were directories — and how the overwhelming majority of code still works. The WordPress.org plugin directory is a convenience, not a requirement. Helpful when it works. Optional when it doesn't.

One other thing worth noting.

The name Lingua Forge now has a history. A public repository. A versioned changelog. A live website it was built for. A direct distribution channel. By the time this dispute resolves itself one way or another, "Lingua Forge" won't be an unclaimed possibility — it'll be an established fact about a specific piece of software that's been in use.

If a different plugin surfaces on WordPress.org under the same name, it'll be entering a landscape where this one already exists and is already distributed. The review process that was supposed to prevent confusion will have produced exactly the overlap it was designed to avoid — just outside its own walls instead of inside them.

---

*Lingua Forge is a free, open-source multilingual WordPress plugin. Source code at [github.com/leotiger/lingua-forge](https://github.com/leotiger/lingua-forge). Available for direct download at [lingua-forge.com](https://lingua-forge.com). Currently in a naming dispute with an unnamed party, as flagged by a classifier that hasn't shared its sources and confirmed by a reviewer who won't name them either.*
