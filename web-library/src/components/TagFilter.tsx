import { useState, useMemo } from 'react';
import type { ZoteroTag } from '../types/zotero';

interface Props {
  tags: ZoteroTag[];
  selectedTags: string[];
  onToggleTag: (tag: string) => void;
}

export default function TagFilter({ tags, selectedTags, onToggleTag }: Props) {
  const [filter, setFilter] = useState('');
  const [collapsed, setCollapsed] = useState(false);

  const filtered = useMemo(() => {
    const sorted = [...tags].sort((a, b) => a.tag.localeCompare(b.tag));
    if (!filter) return sorted;
    const lower = filter.toLowerCase();
    return sorted.filter((t) => t.tag.toLowerCase().includes(lower));
  }, [tags, filter]);

  return (
    <div className={`tag-filter ${collapsed ? 'collapsed' : ''}`}>
      <div className="tag-filter-header" onClick={() => setCollapsed(!collapsed)}>
        <span>{collapsed ? '▸' : '▾'} Tags</span>
        {selectedTags.length > 0 && (
          <span className="tag-count">{selectedTags.length} selected</span>
        )}
      </div>
      {!collapsed && (
        <>
          <input
            type="text"
            className="tag-search"
            placeholder="Filter tags..."
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
          />
          <div className="tag-list-scroll">
            {filtered.map((t) => (
              <span
                key={t.tag}
                className={`tag-item ${selectedTags.includes(t.tag) ? 'selected' : ''}`}
                onClick={() => onToggleTag(t.tag)}
                title={`${t.tag} (${t.meta.numItems})`}
              >
                {t.tag}
              </span>
            ))}
            {filtered.length === 0 && (
              <span className="tag-empty">No tags</span>
            )}
          </div>
        </>
      )}
    </div>
  );
}
