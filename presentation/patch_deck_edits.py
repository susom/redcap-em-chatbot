#!/usr/bin/env python3
"""Apply the presenter's slide edits to a hand-edited copy of the deck.

The deck has diverged from `build_deck.py` (extra slides, reordering, retitles
made in Google Slides), so a full rebuild would throw that work away. This
script matches slides by title and rewrites only the ones listed below, then
sets speaker notes on the presenter's slides.

What it changes:
  · "The conversation keeps going" — realistic two-turn chat (males in study →
    baseline complete), each reply followed by the EM's own "Used tools:" line
  · "From chore to chat" — records.get instead of records.save (writes are
    still in testing), tools line instead of the chips
  · "Building one request" — drops the token composition bar and replaces it
    with the real assembly order of the single system message
  · speaker notes for "Anatomy of that answer" … "Building one request"

Usage:
    python3 patch_deck_edits.py <in.pptx> <out.pptx>
"""
import sys

from pptx.enum.text import PP_ALIGN, MSO_ANCHOR
from pptx.util import Inches
from pptx import Presentation

import build_deck as bd

TOOLS_PREFIX = "Used tools: "


def slide_title(slide):
    return slide.shapes.title.text.strip() if slide.shapes.title is not None else ""


def find(prs, title):
    for s in prs.slides:
        if slide_title(s) == title:
            return s
    raise LookupError(f"slide not found: {title!r}")


def clear_body(slide):
    """Delete every shape except the title placeholder."""
    title = slide.shapes.title
    for shp in list(slide.shapes):
        if title is not None and shp._element is title._element:
            continue
        shp._element.getparent().remove(shp._element)


def tools_line(slide, text, x, y, w=7.0):
    """The EM renders its tool list as a small italic grey line under the reply."""
    return bd.textbox(slide, x, y, w, 0.26, [
        bd.para(TOOLS_PREFIX + text, size=10.5, italic=True, color=bd.GRAY)])


# ------------------------------------------------------------------ slides
def build_conversation(s):
    clear_body(s)
    bd.bubble_user(s, "How many males are enrolled in this study?",
                   7.0, 1.72, 5.82, 0.58, size=13.5)
    bd.bubble_cappy(s, [bd.para("There are 58 male participants out of 142 "
                                "enrolled.", size=13, align=PP_ALIGN.LEFT)],
                    0.51, 2.42, 7.6, 0.6)
    tools_line(s, "projects.getMetadata, records.search", 1.13, 3.10)

    bd.bubble_user(s, "How many of them completed the baseline visit?",
                   7.0, 3.52, 5.82, 0.58, size=13.5)
    bd.bubble_cappy(s, [bd.para("41 of those 58 have the baseline visit marked "
                                "complete.", size=13, align=PP_ALIGN.LEFT)],
                    0.51, 4.22, 7.6, 0.6)
    tools_line(s, "records.search", 1.13, 4.90)

    g = bd.rounded(s, 0.51, 5.45, 12.32, 1.05, bd.FOG_LIGHT,
                   line_color=bd.SAND, radius=0.06)
    accent = s.shapes.add_shape(bd.MSO_SHAPE.RECTANGLE, Inches(0.51),
                                Inches(5.45), Inches(0.09), Inches(1.05))
    accent.fill.solid()
    accent.fill.fore_color.rgb = bd.CARDINAL
    accent.line.fill.background()
    accent.shadow.inherit = False
    tf = g.text_frame
    tf.margin_left = Inches(0.25)
    tf.margin_top = Inches(0.08)
    bd.set_runs(tf, [{
        "runs": [("Guardrail — code vs. label.  ",
                  {"size": 13.5, "bold": True, "color": bd.CARDINAL}),
                 ("Sex is stored as 0/1, severity as 1/2/3. Asked to guess, a "
                  "model will cheerfully tell you 0 means Female when your "
                  "dictionary says Male — so Cappy reads the choice labels out "
                  "of your data dictionary before it answers, and shows the "
                  "label beside the code.",
                  {"size": 13, "color": bd.INK})],
        "align": PP_ALIGN.LEFT, "space_after": None, "space_before": None,
        "line": 1.08}], anchor=MSO_ANCHOR.MIDDLE)


def build_chores(s):
    clear_body(s)
    bd.bubble_user(s, "Pull up record 1042 and get me their baseline survey "
                      "link.", 5.3, 1.78, 7.52, 0.6, size=13.5)
    bd.card(s, 0.51, 2.66, 5.95, 1.75, "records.get — the whole record at once",
            "Every field for one record, including repeating instruments and "
            "event data — instead of clicking through six forms to read three "
            "values.", body_size=12.5)
    bd.card(s, 6.86, 2.66, 5.95, 1.75, "survey.getLink — instant links",
            "A survey URL for any record, instrument, event, or repeat "
            "instance — the end of “let me click through six pages to find "
            "your link”.", body_size=12.5)
    bd.bubble_cappy(s, [{
        "runs": [("Record 1042 — baseline visit complete, enrolled "
                  "2026-03-14. Survey link:  ",
                  {"size": 13, "color": bd.INK}),
                 ("redcap.stanford.edu/surveys/?s=XYZ123",
                  {"size": 12.5, "color": bd.CARDINAL, "font": bd.MONO})],
        "align": PP_ALIGN.LEFT, "space_after": None, "space_before": None,
        "line": None}], 0.51, 4.72, 10.4, 0.65)
    tools_line(s, "records.get, survey.getLink", 1.13, 5.48)

    bar = bd.rounded(s, 0.51, 6.05, 12.32, 0.55, bd.FOG, radius=0.5)
    bd.set_runs(bar.text_frame, [{
        "runs": [("Read-only today — every call is logged and traceable.  ",
                  {"size": 12.5, "bold": True, "color": bd.STONE}),
                 ("Writes (records.save) are still in testing.",
                  {"size": 12.5, "color": bd.STONE})],
        "align": PP_ALIGN.CENTER, "space_after": None, "space_before": None,
        "line": None}], anchor=MSO_ANCHOR.MIDDLE)


def build_request(s):
    """Keep the four stages; replace the token chart with the real assembly
    order of the one system message Cappy builds."""
    keep_prefixes = ("1 · ", "2 · ", "3 · ", "4 · ", "What do you actually",
                     "You")
    title = s.shapes.title
    for shp in list(s.shapes):
        if title is not None and shp._element is title._element:
            continue
        txt = shp.text_frame.text.strip() if shp.has_text_frame else ""
        is_arrow = shp.shape_type is not None and "LINE" in str(shp.shape_type)
        if is_arrow or any(txt.startswith(p) for p in keep_prefixes):
            continue
        shp._element.getparent().remove(shp._element)

    bd.textbox(s, 0.51, 4.02, 12.32, 0.28, [{
        "runs": [("Inside step 2  ", {"size": 12.5, "bold": True,
                                      "color": bd.INK}),
                 ("— it all lands in ONE system message, appended in this "
                  "order:", {"size": 12, "color": bd.STONE})],
        "align": PP_ALIGN.LEFT, "space_after": None, "space_before": None,
        "line": None}])

    layers = [
        ("Page context", "the page you have open"),
        ("Field index", "every field name → label (optional)"),
        ("Form metadata", "choices & branching (off by default)"),
        ("Retrieved documents", "only if RAG finds a match"),
        ("Rules, lock, admin prompt", "display rules first, admin prompt last"),
    ]
    x, w, gap = 0.51, 2.288, 0.22
    for i, (head, sub) in enumerate(layers):
        cx = x + i * (w + gap)
        c = bd.rounded(s, cx, 4.40, w, 0.88, bd.FOG_LIGHT, line_color=bd.SAND,
                       radius=0.07)
        tf = c.text_frame
        tf.margin_left = tf.margin_right = Inches(0.09)
        tf.margin_top = Inches(0.06)
        bd.set_runs(tf, [
            bd.para(head, size=10.5, bold=True, color=bd.CARDINAL,
                    align=PP_ALIGN.CENTER, space_after=2, line=0.95),
            bd.para(sub, size=9, color=bd.STONE, align=PP_ALIGN.CENTER,
                    line=0.95),
        ], anchor=MSO_ANCHOR.MIDDLE)
        if i < len(layers) - 1:
            bd.arrow(s, cx + w + 0.03, 4.84, cx + w + gap - 0.03, 4.84,
                     color=bd.SAND, weight=1.5)

    bd.textbox(s, 0.51, 5.45, 12.32, 0.6, [{
        "runs": [("Then SecureChatAI prepends a second system message — the "
                  "router prompt and the tool menu — followed by your "
                  "conversation and your question. ",
                  {"size": 11.5, "color": bd.INK}),
                 ("It also caps the run: 8 steps, 15 tool calls, 120 seconds, "
                  "and every tool result truncated to 8 KB.",
                  {"size": 11.5, "color": bd.STONE})],
        "align": PP_ALIGN.LEFT, "space_after": None, "space_before": None,
        "line": 1.15}])

    bar = bd.rounded(s, 0.51, 6.28, 12.32, 0.55, bd.FOG, radius=0.5)
    bd.set_runs(bar.text_frame, [bd.para(
        "What we send is your project’s map — field names and labels. Record "
        "data only ever arrives as the result of a tool call.",
        size=13.5, bold=True, color=bd.CARDINAL, align=PP_ALIGN.CENTER)],
        anchor=MSO_ANCHOR.MIDDLE)


# ------------------------------------------------------------------- notes
NOTES = {
"Anatomy of that answer": """SAY IT IN PLAIN ENGLISH — this slide is for the coordinators and PIs in the room, not the engineers.

Opening: "That answer looked like magic. It isn't, and I want you to be able to explain it to your PI."

The analogy that lands: Cappy is the colleague who knows which REDCap search to run. REDCap is still the filing cabinet — and only REDCap is allowed to open the drawer. The AI never touches your data directly; it asks, REDCap answers.

Walk the six steps, don't read them. Beats to hit:
· Step 2 — "pre-approved actions, in the project you have open" is the whole security story in one line.
· Step 4 — the labels come from YOUR data dictionary. This is the one people remember: "3" means "3 — Severe" because your project says so.
· Step 6 — paging doesn't re-ask the AI, so the numbers on screen can't drift between clicks.

Land on the bar: "Cappy decides what to ask. REDCap decides what's true." Pause there — it's the sentence you want them to repeat to their PI.

IF ASKED, the engineering version:
· The model plans a records.search with a REDCap logic filter; it is a planner, never an executor.
· SecureChatAI validates the call against tools.json and invokes the tool EM in the same PHP process — no HTTP hop, no API token.
· Record Tools runs native getData, so user rights and project scoping are REDCap's, not ours.
· Tool results are truncated at 8 KB before they go back to the model.

HEADS-UP on your build: steps 3, 5 and 6 (cached result set, preview built server-side, instant Next) ship in the newer REDCapAgentRecordTools on origin/master. If you demo a checkout that predates it, the table you see was rendered by the model from the rows it fetched — still correct, but say "the model renders it from what the tool returned" rather than "the server builds the preview".""",

"The conversation keeps going": """THE POINT: it's a conversation, not a search box. Context carries from one question to the next.

Opening: "Watch what happens on the second question — I never say 'males' again."

Walk it:
· First question is the kind of thing a coordinator actually asks: how many males are enrolled.
· Cappy answers with a number, and underneath tells you exactly which tools it used. That grey line is real UI, not a slide decoration — every answer shows its work.
· Second question says "of them". No repeated criteria, no re-explaining the cohort. That's the conversation carrying its own context.

Point at the tools line: "projects.getMetadata, records.search — that's Cappy looking up your field names first, then running one filtered search. If it ever does something you didn't expect, it's right there in the answer."

Then the guardrail — this is the slide's real payload:
Ask the room: "In your project, does 0 mean Male or Female?" Half of them won't be sure. Neither is a language model. That's exactly why Cappy pulls choice labels out of the data dictionary before answering, and shows the label next to the code. An assistant that silently flips your sex coding is worse than no assistant at all.

IF ASKED — the numbers on this slide are illustrative; the live screenshot earlier in the deck is a real run.
IF ASKED about repeated questions costing money/time: each turn is one agent run with a hard cap (8 steps, 15 tool calls, 120 seconds).""",

"From chore to chat": """THE POINT: the boring stuff. This is where people stop being impressed and start being interested.

Opening: "Nobody's PI is excited about AI. They're excited about not clicking through six screens to find one survey link."

Walk it:
· One sentence asks for two different things — a record's details and a survey link — and Cappy does both in one turn.
· records.get pulls the whole record at once: every field, repeating instruments, event data. The chore it replaces is opening a record, clicking through forms, and reading three values off different pages.
· survey.getLink is the one everyone at this conference has done the slow way. Record, instrument, event, repeat instance — one link, straight in the chat.
· The tools line under the reply again: records.get, survey.getLink. Nothing happened that isn't named right there.

Be straight about scope (bottom bar): today's toolbox is read-only. records.save — creating and updating data — exists but is still in testing, and we're not shipping writes into anyone's production project until we're confident in the validation and the audit trail. Say it plainly; in this room it buys you credibility rather than costing it.

IF ASKED "when do writes land?" — it's the next thing on the list, gated on the governance registry (per-project tool allowlists) so an admin can decide which projects may write at all.
IF ASKED about user rights: tools call REDCap's own getData/save paths, so a user can never reach data through Cappy that they couldn't reach in the UI.""",

'"Can I trust you?"': """THE POINT: this is the question every admin in the room is already holding. Answer it before they ask.

Opening: "Let's do the uncomfortable question. You're an admin. Why would you turn this on?"

Two halves, two different audiences — take the left column for the administrators, the right for the engineers:

GOVERNED (humans decide):
· Agent mode is double opt-in — the Control Center flag AND the project setting must both be on. One switch is never enough.
· Models are approved and routed centrally through SecureChatAI; a project can't point Cappy at some random API.
· Admins own the prompts, the scope, and the tone per project. Configuration, not code.
· Coming: a governance registry — per-project tool allowlists, rate limits, an audit UI.

ENGINEERED (the model can't misbehave even if it wants to):
· The LLM plans; it never executes. Only registered tools run, EM-to-EM in the same PHP process — no HTTP surface, no API tokens floating around.
· Everything is pid-scoped: tools require the project ID, and the prompt hard-locks Cappy to the project you have open. Ask it about another project and it declines.
· PHI is scrubbed from debug logs, front end and back.
· Every tool call is logged and independently auditable.

Land on Cappy's line: "I can only use tools you registered, in the project you opened — and everything I do is logged."

IF ASKED "what if the model hallucinates a tool?" — it can only emit a name from the catalog it was given; anything else fails validation and never reaches REDCap.""",

"Building one request": """THE POINT: the "what leaves my server" slide. Security reviewers ask this; have the answer ready.

Opening: "Somebody always asks what we actually send to the model. Here it is, end to end."

Walk the four boxes left to right — browser, Cappy, SecureChatAI, model. Then drop into step 2, because that's the part people assume is hand-wavy: everything Cappy adds is appended into ONE system message, in that order — page context, the field index, optional form metadata, any retrieved documents, and the rules plus project lock plus your admin's prompt last.

Then the line underneath: SecureChatAI prepends a second system message with the router prompt and the tool menu, and puts a hard cap on the run — 8 steps, 15 tool calls, 120 seconds, every tool result truncated to 8 KB.

Land on the bar: what we send is your project's MAP — field names and labels — not your data. Record data only ever arrives as the result of a tool call, and only for the rows that call returned.

IF ASKED how big that is: on a real 832-field dictionary the field index measures ~93 KB, roughly 23,000 tokens, which is the bulk of the request. It's a per-project setting — switch it off and the agent discovers field names with a tool call instead. Our widest local project has 18,696 fields; its index alone would be ~815K tokens, which is exactly why it's a toggle and not a constant.
IF ASKED about function calling: agent mode does NOT use native OpenAI tool calling — the tool menu is text and the reply is constrained by a JSON schema, which keeps the loop portable across approved models.
IF ASKED where the model lives: an institution-approved endpoint via SecureChatAI. Nothing goes to a public consumer API.""",
}


def main():
    src, out = sys.argv[1], sys.argv[2]
    prs = Presentation(src)

    for title, builder in (("The conversation keeps going", build_conversation),
                           ("From chore to chat", build_chores),
                           ("Building one request", build_request)):
        s = find(prs, title)
        builder(s)
        print(f"rebuilt: {title}")

    for title, text in NOTES.items():
        bd.notes(find(prs, title), text)
        print(f"notes:   {title} ({len(text)} chars)")

    prs.save(out)
    print(f"saved {out} with {len(prs.slides._sldIdLst)} slides")


if __name__ == "__main__":
    main()
