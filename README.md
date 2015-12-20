OEIS API
========

This is an **ABANDONED** project that was supposed to build a nice, RESTful API around the
OEIS, but as the maintainers proved to not understand their own license and to be unwilling to
let others spread their data, I decided to not finish it.

In its current state, the crawling/importing code mostly works for things. It's based on parsing the
HTML instead of the internal, text-based format (yes, I knew about that one), because extracting
usernames and links is *much* easier that way. Some things are left unfinished, though.

The goal was to provide semantic JSON for each sequence. My attempt eventually lead to the OEIS
offering a JSON version themselves, which unfortunately completely misses the point (as you still
have to parse the basically line-based format) of my project.

Take from this what you want. The parsing code is pretty good, the rest is just a stub for a Silex
app with MariaDB in the background for indexing purposes. There's also some crawling code in
``www/``, where I spread the crawling throughout 3 VMs. It's all pretty hackish, though.

**DO NOT use this in production, as you will make people at the OEIS sad.**

Even though this package has a ``composer.json``, it's not registered on packagist. It jsut
documents the dependencies.
