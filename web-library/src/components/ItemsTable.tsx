import { useState, useRef, useEffect } from 'react';
import type { ZoteroItem } from '../types/zotero';

interface Column {
  field: string;
  label: string;
  getValue: (item: ZoteroItem) => string;
}

const ALL_COLUMNS: Column[] = [
  { field: 'creator', label: 'Creator', getValue: (item) => item.meta.creatorSummary || formatCreators(item) },
  { field: 'date', label: 'Date', getValue: (item) => item.meta.parsedDate || item.data.date || '' },
  { field: 'itemType', label: 'Item Type', getValue: (item) => item.data.itemType },
  { field: 'year', label: 'Year', getValue: (item) => (item.meta.parsedDate || item.data.date || '').substring(0, 4) },
  { field: 'publisher', label: 'Publisher', getValue: (item) => item.data.publisher || '' },
  { field: 'publicationTitle', label: 'Publication Title', getValue: (item) => item.data.publicationTitle || '' },
  { field: 'dateAdded', label: 'Date Added', getValue: (item) => item.data.dateAdded ? new Date(item.data.dateAdded).toLocaleDateString() : '' },
  { field: 'dateModified', label: 'Date Modified', getValue: (item) => item.data.dateModified ? new Date(item.data.dateModified).toLocaleDateString() : '' },
  { field: 'extra', label: 'Extra', getValue: (item) => item.data.extra || '' },
  { field: 'DOI', label: 'DOI', getValue: (item) => item.data.DOI || '' },
  { field: 'volume', label: 'Volume', getValue: (item) => item.data.volume || '' },
  { field: 'issue', label: 'Issue', getValue: (item) => item.data.issue || '' },
  { field: 'pages', label: 'Pages', getValue: (item) => item.data.pages || '' },
  { field: 'journalAbbreviation', label: 'Journal Abbr', getValue: (item) => item.data.journalAbbreviation || '' },
];

const DEFAULT_VISIBLE = ['creator', 'date', 'itemType'];

function formatCreators(item: ZoteroItem): string {
  const creators = item.data.creators;
  if (!creators || creators.length === 0) return '';
  if (creators.length === 1) return creators[0].lastName || creators[0].name || '';
  return `${creators[0].lastName || creators[0].name || ''} et al.`;
}

interface Props {
  items: ZoteroItem[];
  totalResults: number;
  page: number;
  pageSize: number;
  sortField: string;
  sortDirection: 'asc' | 'desc';
  selectedItemKey: string | null;
  onSelectItem: (key: string) => void;
  onSort: (field: string) => void;
  onPageChange: (page: number) => void;
  loading: boolean;
}

export default function ItemsTable({
  items,
  totalResults,
  page,
  pageSize,
  sortField,
  sortDirection,
  selectedItemKey,
  onSelectItem,
  onSort,
  onPageChange,
  loading,
}: Props) {
  const [visibleFields, setVisibleFields] = useState<string[]>(() => {
    const saved = localStorage.getItem('zotero_visible_columns');
    return saved ? JSON.parse(saved) : DEFAULT_VISIBLE;
  });
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    localStorage.setItem('zotero_visible_columns', JSON.stringify(visibleFields));
  }, [visibleFields]);

  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setMenuOpen(false);
      }
    }
    if (menuOpen) document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [menuOpen]);

  const toggleColumn = (field: string) => {
    setVisibleFields((prev) =>
      prev.includes(field) ? prev.filter((f) => f !== field) : [...prev, field],
    );
  };

  const restoreDefaults = () => {
    setVisibleFields(DEFAULT_VISIBLE);
  };

  const visibleColumns = ALL_COLUMNS.filter((c) => visibleFields.includes(c.field));
  const titleWidth = Math.max(30, 90 - visibleColumns.length * 15);
  const colWidth = visibleColumns.length > 0 ? `${(100 - titleWidth) / visibleColumns.length}%` : '0';

  const totalPages = Math.max(1, Math.ceil(totalResults / pageSize));
  const colSpan = 1 + visibleColumns.length;

  return (
    <div className="items-table-container">
      <table className="items-table">
        <thead>
          <tr>
            <th
              style={{ width: `${titleWidth}%` }}
              className={sortField === 'title' ? 'sorted' : ''}
              onClick={() => onSort('title')}
            >
              Title
              {sortField === 'title' && (
                <span className="sort-arrow">{sortDirection === 'asc' ? ' ▲' : ' ▼'}</span>
              )}
            </th>
            {visibleColumns.map((col) => (
              <th
                key={col.field}
                style={{ width: colWidth }}
                className={sortField === col.field ? 'sorted' : ''}
                onClick={() => onSort(col.field)}
              >
                {col.label}
                {sortField === col.field && (
                  <span className="sort-arrow">{sortDirection === 'asc' ? ' ▲' : ' ▼'}</span>
                )}
              </th>
            ))}
            <th style={{ width: '32px', padding: '0' }} className="col-menu-th">
              <div className="col-menu-wrapper" ref={menuRef}>
                <button
                  className="col-menu-btn"
                  onClick={(e) => { e.stopPropagation(); setMenuOpen(!menuOpen); }}
                  title="Choose columns"
                >
                  <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                    <rect x="1" y="1" width="4" height="14" rx="1" opacity="0.6"/>
                    <rect x="6" y="1" width="4" height="14" rx="1" opacity="0.8"/>
                    <rect x="11" y="1" width="4" height="14" rx="1" opacity="1"/>
                  </svg>
                </button>
                {menuOpen && (
                  <div className="col-menu-dropdown">
                    {ALL_COLUMNS.map((col) => (
                      <label key={col.field} className="col-menu-item" onClick={() => toggleColumn(col.field)}>
                        <span className="col-check">{visibleFields.includes(col.field) ? '✓' : ''}</span>
                        {col.label}
                      </label>
                    ))}
                    <div className="col-menu-divider" />
                    <label className="col-menu-item" onClick={restoreDefaults}>
                      <span className="col-check" />
                      Restore Column Order
                    </label>
                  </div>
                )}
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          {loading ? (
            <tr><td colSpan={colSpan + 1} className="loading-cell">Loading...</td></tr>
          ) : items.length === 0 ? (
            <tr><td colSpan={colSpan + 1} className="empty-cell">No items</td></tr>
          ) : (
            items.map((item) => (
              <tr
                key={item.key}
                className={selectedItemKey === item.key ? 'selected' : ''}
                onClick={() => onSelectItem(item.key)}
              >
                <td className="item-title">{item.data.title || '(Untitled)'}</td>
                {visibleColumns.map((col) => (
                  <td key={col.field}>{col.getValue(item)}</td>
                ))}
                <td style={{ width: '32px', padding: '0' }} />
              </tr>
            ))
          )}
        </tbody>
      </table>

      {totalPages > 1 && (
        <div className="pagination">
          <button disabled={page <= 1} onClick={() => onPageChange(page - 1)}>‹ Prev</button>
          <span className="page-info">{page} / {totalPages} ({totalResults} items)</span>
          <button disabled={page >= totalPages} onClick={() => onPageChange(page + 1)}>Next ›</button>
        </div>
      )}
    </div>
  );
}
