import DOMPurify from 'dompurify';

// Zotero stores a limited subset of rich-text markup in certain fields (e.g.
// titles): <i>, <b>, <sub>, <sup>, and <span class="nocase">. The nocase span
// only matters for citation title-casing and has no visual meaning, so we drop
// any disallowed tag while keeping its text content (DOMPurify KEEP_CONTENT
// defaults to true). Everything else (scripts, attributes, event handlers) is
// stripped, so the output is safe to inject as HTML.
const RICH_TEXT_CONFIG = {
  ALLOWED_TAGS: ['i', 'b', 'sub', 'sup'],
  ALLOWED_ATTR: [],
};

export function renderRichText(value: string): string {
  return DOMPurify.sanitize(value, RICH_TEXT_CONFIG);
}

interface RichTextProps {
  value: string;
  className?: string;
}

// Renders Zotero rich-text markup (sanitized) for display-only contexts.
export function RichText({ value, className }: RichTextProps) {
  return (
    <span
      className={className}
      dangerouslySetInnerHTML={{ __html: renderRichText(value) }}
    />
  );
}
