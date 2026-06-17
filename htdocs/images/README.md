# Home page images

Drop a screenshot of **this server's Web Library** here as:

    zotero.png

It is shown in the hero section of `htdocs/home.html`. Until the file
exists, a labelled placeholder box is shown in its place (via `onerror`),
so the landing page still looks clean.

Suggested capture:
- Open `/library/`, sign in, select **My Library** with a few items visible.
- Capture the 3-panel view (collections | items | detail).
- Save as PNG, reasonably wide (e.g. ~1200px). Optimize if large.

This image is served directly by nginx from the repo (no build step), so
committing `weblibrary.png` is all that's needed to make it live.

## Web clipper screenshot

Drop a screenshot of the **Zotero Connector / web clipper** here as:

    web-clipper.png

It is shown in the "Save from your browser" section of `htdocs/home.html`.
Until the file exists, a labelled placeholder box is shown in its place
(via `onerror`).

Suggested capture:
- Open a web page (e.g. an article), click the Zotero Connector toolbar
  button so the save popup is visible.
- Capture the browser with the connector popup showing.
- Save as PNG, reasonably wide (e.g. ~1200px). Optimize if large.
