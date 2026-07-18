<p align="center">
  <img src="assets/images/mascott_party.svg" alt="Quill the iSubmit mascot, throwing confetti" width="200">
</p>

<h1 align="center">💜 iSubmit — Research Digitalization Platform 💜</h1>

<p align="center">
  <b>hi, i'm <i>Quill</i>!</b> 🪶✨ your bubbly little research buddy~<br>
  i help students & faculty turn messy paper submissions into <b>soft, sparkly, digital magic.</b> 🔮🫐
</p>

<p align="center">
  🟣 built with love for <b>ISAP</b> & <b>MCNP</b> 🟣
</p>

---

## 🪻 what even is this? (a tiny intro)

iSubmit is a cozy-but-powerful platform that handles the **whole life** of an academic research submission — uploading, consulting, revising, and getting that final *approved* stamp. 🌟 No more lost files, no more scary email chains. Just you, Quill, and a smooth glowy workflow. 👾💜

---

## 🔮 the sparkly features

- **🪶 Quill, your little guardian** — our interactive SVG mascot watches your cursor, shyly covers his eyes when you type your password, and waves hi when you arrive. so cute i could cry. 🥹
- **🎀 role-based comfy rooms** — dedicated dreamy interfaces for **Students, Coordinators, Directors & Statisticians**. everyone gets their own space~
- **🫐 messaging that feels like a hug (v2.0)** — a Slack-meets-iMessage chat, all Vanilla JS & AJAX, no heavy frameworks weighing it down:
  - **🌌 sneaky-smart routing** — students message staff by *title* (Coordinator, Statistician) without needing their real identity. mysterious~ 🕵️‍♀️
  - **💜 tappy emoji reactions** — double-tap or hover any message to react (❤️ 👍 😂 …), saved to the DB instantly.
  - **🟣 smart little sidebar** — pinned groups + live chat streams, all tidy and responsive.
- **🌙 mood theming** — branding & colors auto-switch by school email (`@isap.edu.ph` 🟣 vs `@mcnp.edu.ph` 💜).
- **📂 research lifecycle magic** — track submissions, handle revisions, and keep a comfy consultation log with your advisers.
- **🔐 OTP & security cuddles** — email verification + secure password recovery so nothing sketchy sneaks in.

---

## 🧸 the tech behind the sparkle

| ✨ layer | 💜 made of |
|---|---|
| 🎨 **frontend** | HTML5, CSS3 (custom vars, Flexbox/Grid), Vanilla JS *(no chunky frameworks!)* |
| ⚙️ **backend** | PHP 8.2+ with PDO |
| 🫐 **database** | MySQL / MariaDB (`digital_research`) |
| 🪄 **assets** | Lucide Icons, Google Fonts (Poppins & Plus Jakarta Sans) |
| 📦 **deps** | Composer, PHPMailer, smalot/pdfparser |

---

## 🗂️ where the magic lives

```
/
├── 🎨 assets/       # CSS, JS, Images & Quill's SVG wardrobe
├── 🔐 auth/         # Login, Register, OTP & Password Recovery
├── ⚙️ config/       # Database (db.php) & Mail (mail.php)
├── 🏠 dashboards/   # Role-based portals (Student, Director, Statistician…)
├── 🧪 scratch/      # Temporary playground files
├── 📤 uploads/      # User-uploaded research docs (PDF, Docx, Xlsx)
├── 📦 storage/      # Historical files
└── 🧩 vendor/       # Composer dependencies
```

---

## 📖 the little library (docs)

wanna go deeper? Quill left notes for you inside the `docs/` folder~ 🪶💜

- 🛠️ [INSTALLATION.md](docs/INSTALLATION.md) — set up your own cozy local copy
- 🏛️ [SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) — how the front & back fit together
- 🫐 [DATABASE_DOCUMENTATION.md](docs/DATABASE_DOCUMENTATION.md) — schema, constraints & tables
- 🔐 [SECURITY.md](docs/SECURITY.md) — auth & keeping the baddies out
- 🚀 [DEPLOYMENT.md](docs/DEPLOYMENT.md) — sending iSubmit to the big scary internet
- 🤝 [CONTRIBUTING.md](docs/CONTRIBUTING.md) — join the sparkle
- 📝 [CHANGELOG.md](docs/CHANGELOG.md) — every glow-up we've had
- 🌈 [TODO.md](docs/TODO.md) / [ROADMAP.md](docs/ROADMAP.md) — dreams still to come

---

<p align="center">
  <img src="assets/images/mascot.svg" alt="Quill in a graduation cap" width="90"><br>
  <i>made with 💜 (and a little confetti 🎊) for the students & faculty of ISAP & MCNP.</i><br>
  <b>go be brilliant~ 🌟🟣</b>
</p>
