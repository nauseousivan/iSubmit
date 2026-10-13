# If I had built iSubmit "process first"

A look back, not a redo plan. Nothing in the system changes because of this file.

---

## 1. What happened

iSubmit slowly turned into a **file repository**. Every requirement became an upload, and every upload became
something staff had to open and approve. The screens grew around that: requirement grids, review modals,
version history, checklists by group, approval queues.

But that is not how the research process really works. From what you saw first-hand:

| What the system assumes | What really happens |
|---|---|
| Staff check the papers inside the system | **Checking is physical.** The research teacher checks the write-up and the formatting, on paper or on someone's laptop |
| The Focal Person reviews each requirement | The Focal Person mostly **signs**. They don't really check |
| The people who check have accounts | The **research teacher** and the **dean** check, and **neither has an account** |
| Approval is spread across roles | The **Coordinator** does most of the real validation (you found this out yesterday) |
| A digital copy replaces the paper | **The copies are still physical.** The routing still happens by hand |

So the system digitized the **documents**. The thing people actually needed help with was the **process**:
where is our paper, who has it, who still needs to sign, what do we bring next.

---

## 2. The one idea I would build around

> **Track the paper's journey, not the paper itself.**

Think **parcel tracking**, not **Google Drive**. A delivery app doesn't hold your package. It tells you
where your package is, who has it, and what happens next. iSubmit should have done the same for a research
paper.

So the main thing the system stores would not be a file. It would be a **checkpoint**:

```
Group  ·  Step  ·  Who signed it off  ·  When  ·  Note (if sent back)  ·  (optional) file
```

A file is attached **only** where the office truly needs a digital copy. Everywhere else, the checkpoint
just records that it happened.

---

## 3. Step one: map the real process before drawing any screen

Before any code, I would sit with the Coordinator (and follow one group through one stage) and fill in a table
like this. This table *is* the design. Everything else comes from it.

| Stage | What happens | Who really does it | Has an account? | Where it happens | What the system records |
|---|---|---|---|---|---|
| Write-up check | Content and formatting are checked | Research teacher | **No** | Physical / laptop | ✔ "Checked by teacher", date, by whom |
| Dean's check | Dean reviews and signs | Dean | **No** | Physical | ✔ "Signed by dean", date |
| Validation | Everything complete and correct? | **Coordinator** | Yes | Physical copy + system | ✔ Validated, or ✖ returned with a note |
| Endorsement | Section endorsed, date proposed | Focal Person | Yes | System | ✔ Signature |
| Scheduling | Date confirmed and published | Coordinator → Secretary | Yes | System | Date + slot |
| Defense | Panel hears the group | Panel | No | Face to face | ✔ "Defended", result |
| Statistics | Payment, data, treatment, results | Statistician | Yes | **Really digital** | Payment, files, result |
| Plagiarism | Turnitin check of the final manuscript | Office | Yes | **Really digital** | Manuscript PDF, report, %, control no. |
| Final copies | Bound copies submitted | Office | Yes | Physical | ✔ "Received", date |

Look at the last two columns. **Most rows only need a tick, a name and a date.** Only Statistics and
Plagiarism truly need files. That is the opposite of what got built, where almost every row became an upload.

---

## 4. People without accounts still count

The research teacher and the dean do the real checking but will never log in. The system shouldn't pretend
they don't exist. It should **record their sign-off through someone who does have an account**:

- **Simplest:** the Coordinator ticks "Formatting checked by Ms. Reyes, Oct 3" when the group shows the signed
  routing slip. Ten seconds, no account needed for the teacher.
- **Nicer, if there was time:** a one-time **sign-off QR** (like the join-by-QR idea). The teacher scans it on
  their phone and taps "OK" or "Return with note". No account and no password, and the signature is still in the system.

Either way, the student's tracker would honestly show "Waiting for: research teacher".

---

## 5. One job per person

Each role gets **one screen built around the one thing they do**, not a copy of the same review grid:

| Who | Their one screen | Their one action |
|---|---|---|
| **Student** | **"Where is our paper?"** A timeline: done ✔, now ⏳ (with whom), next | Bring the next thing to the right person |
| **Coordinator** | **Validation queue**: groups waiting on her, oldest first | Validate, or return with a note |
| **Focal Person** | **Sign stack**: sections ready to endorse | Sign (endorse a date) |
| **Secretary** | **Schedule**: confirmed dates to log and publish | Log slots, publish |
| **Statistician** | Payment → data → result (this one really is digital) | Move the request forward |
| **Admin** | Accounts | Approve, maintain |

The student screen would have been the heart of the system. Right now students upload into a grid and wait.
A process-first system would answer **"what do we do next, and who has our paper?"** the moment they open it.

---

## 6. What changes, side by side

| Area | What got built | Process first |
|---|---|---|
| Proposal requirements | 7 uploads, each reviewed and approved in the system | 1 routing checklist with 7 ticks; uploads only if the office asks |
| Who approves | Focal Person approves each file | Coordinator validates; Focal Person signs the endorsement |
| Teacher and dean | Not in the system | Recorded as sign-offs (ticked by the Coordinator, or a sign-off QR) |
| Student view | Requirement grid + statuses per file | A tracker: where it is, with whom, what's next |
| Version history | Every re-upload kept | Kept only where files matter (stats, plagiarism) |
| Coordinator's work | Spread across several review screens | One queue |
| Reports | Counts of files and approvals | Counts per **stage**: how many groups are stuck where, and for how long |

---

## 7. How I would have built it (the method)

1. **Watch first.** Follow one group through one stage, and ask the Coordinator "what do you actually check, and on what?"
   One afternoon of this would have shown that checking is physical and that she does most of it.
2. **Draw the routing slip.** Write the stages as a table (section 3). Get the Coordinator to say "yes, that's it."
3. **Build the smallest useful thing:** the student tracker + the Coordinator's queue. No uploads yet.
4. **Try it with one section for one week.** See where people still walk to the office, and why.
5. **Add uploads only when someone asks for a digital copy.** In this case: statistics and plagiarism.
6. **Then** add the extras: scheduling chain, payments, QR, analytics.

The difference is the order. iSubmit started from "what files exist?" A process-first build starts from
"what does each person do, and what are they waiting for?"

---

## 8. What you built is not wasted

A lot of iSubmit already **is** process-first, and would carry straight over:

- **The defense scheduling chain** (Focal Person → Coordinator → Secretary, slots per institution). It is pure process.
- **The Statistics pipeline** ("Progress", banded by whose move it is). This is exactly the parcel-tracking idea.
- **The Plagiarism flow** with control numbers and paid rounds. Genuinely digital, correctly modelled.
- **Admin, accounts, the Help Desk, QR desks, the office calendar, analytics**: all still useful.

The repository parts (requirement grids, per-file approval) don't need to be thrown away either. They become
the **archive**: where the digital copies live when the office wants them. They just shouldn't be the center.

---

## 9. For the defense next week

Don't rebuild anything in a week. Use what you learned. Panels respect a team that **found out how the process really
works** and can say so honestly. Something like:

- *"While testing with the office, we found that checking is still physical, done by the research teacher and
  the dean, and that the Coordinator does most of the validation."*
- *"So the system's real value is **tracking and scheduling**: where each group is, the defense scheduling
  chain, and the statistics and plagiarism steps, which are truly digital."*
- *"The document upload part works as the **digital archive**."*
- **Recommendation / future work:** record sign-offs from people without accounts (teacher, dean), and
  a student-facing "where is our paper" tracker as the main screen.

That turns "the system does too much" into **a finding**, and findings are what a defense is for.
