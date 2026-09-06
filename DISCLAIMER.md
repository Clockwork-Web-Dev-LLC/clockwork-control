# Security, Support & Liability Disclaimer

Clockwork Control is provided to you free, under the MIT license, with no warranty of any kind — not that it works, not that it's fit for your particular setup, not that it's free of bugs. That's not boilerplate we added on top of the license; it's the actual deal. If you use this software, you're accepting it exactly as it exists in the repository, bugs and all, and you're responsible for deciding whether it's safe and appropriate for your own fleet before you rely on it.

Free community support is offered on a best-effort basis, with no guaranteed response time or fix timeline. Paid support arrangements may be available for operators who want dedicated help — see [clockworkcontrol.com](https://clockworkcontrol.com) for current offerings — but that's a separate arrangement, not something this disclaimer or the software itself promises.

This is not a passive dashboard. Clockwork Control stores real credentials for your fleet — SSH keys, database passwords, API tokens — and it can take real, consequential actions on your behalf: banning IPs, running WordPress core/plugin/theme updates, changing WAF or firewall rules, rebooting servers. Any of these can go wrong in ways that take a site offline, lock out an admin, or damage something you care about. Before you enable any automated or autonomous feature, it's on you to actually read what it does, understand the blast radius if it misfires, and test it somewhere that isn't your most important client's production site.

To be unambiguous: the maintainer is not liable for damage, downtime, data loss, or a security breach on any site or server you manage through this software — including if you believe the root cause was a bug, a missing safeguard, or some other gap in Clockwork Control itself. You are the one operating the tool against real infrastructure, and you carry the outcomes of that, the same way you would if you'd written the automation yourself.

A few things worth keeping in mind:

- **No warranty.** Provided "as is," full stop — see the [MIT license](LICENSE) in this repository for the exact legal language.
- **Community support is best-effort.** Bug reports and questions are welcome, but nothing here promises a fix, a reply, or a timeline on the free/community side. Paid support may be available separately.
- **You hold real credentials and real power.** SSH keys, DB passwords, and API tokens for your fleet live in this app, and its automated actions can genuinely break things. Review a feature before you turn it loose.
- **You're liable for your own use.** Compromises, misconfigurations, or damage to sites/servers you manage through this software are your responsibility — including cases where the software itself is the alleged cause.
- **Not legal advice.** This disclaimer explains the practical deal, not a substitute for counsel. If you need a binding opinion about your own liability exposure or your business's obligations to your clients, talk to your own lawyer.

---

For how to report a security vulnerability (different from the above), see [SECURITY.md](SECURITY.md).
