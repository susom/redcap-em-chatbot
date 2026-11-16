#!/usr/bin/env python3
"""Apply the agent-mode slide rebuild to a hand-edited copy of the deck.

Use this when the deck has been round-tripped through Google Slides / PowerPoint
and therefore carries edits that `build_deck.py` does not know about (extra
slides, reordering, retitles). It surgically:

  1. deletes the "Building one request" slide, if still present, and
  2. replaces every non-title shape on the "Agent mode …" slide with the
     current `build_deck.build_agent_mode()` content (demo screenshot on the
     left, the four assembly stages down the right rail).

Everything else in the file is left untouched.

Usage:
    python3 patch_agent_slide.py <in.pptx> <out.pptx>
"""
import sys

from pptx import Presentation
from pptx.oxml.ns import qn

import build_deck as bd

DROP_SLIDE_TITLES = ("Building one request",)
AGENT_TITLE_PREFIX = "Agent mode"


def slide_title(slide):
    return slide.shapes.title.text if slide.shapes.title is not None else ""


def delete_slide(prs, slide):
    """Remove a slide from the presentation (python-pptx has no API for it)."""
    slide_id = None
    for sld_id in prs.slides._sldIdLst:
        if prs.part.related_part(sld_id.get(qn("r:id"))) is slide.part:
            slide_id = sld_id
            break
    if slide_id is None:
        raise LookupError("slide not found in sldIdLst")
    prs.part.drop_rel(slide_id.get(qn("r:id")))
    prs.slides._sldIdLst.remove(slide_id)


def clear_body_shapes(slide):
    """Delete every shape except the title placeholder."""
    title = slide.shapes.title
    for shp in list(slide.shapes):
        if title is not None and shp._element is title._element:
            continue
        shp._element.getparent().remove(shp._element)


def main():
    src, out = sys.argv[1], sys.argv[2]
    prs = Presentation(src)

    for slide in list(prs.slides):
        if slide_title(slide).strip() in DROP_SLIDE_TITLES:
            print(f"dropping slide: {slide_title(slide)!r}")
            delete_slide(prs, slide)

    patched = 0
    for slide in prs.slides:
        if slide_title(slide).strip().startswith(AGENT_TITLE_PREFIX):
            print(f"rebuilding slide: {slide_title(slide)!r}")
            clear_body_shapes(slide)
            slide.shapes.title.text = bd.AGENT_SLIDE_TITLE
            bd.build_agent_mode(slide)
            patched += 1
    if patched != 1:
        raise SystemExit(f"expected exactly one '{AGENT_TITLE_PREFIX}…' slide, "
                         f"found {patched}")

    prs.save(out)
    print(f"saved {out} with {len(prs.slides._sldIdLst)} slides")


if __name__ == "__main__":
    main()
