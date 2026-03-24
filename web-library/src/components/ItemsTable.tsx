import { useState, useRef, useEffect } from 'react';
import type { ZoteroItem } from '../types/zotero';

function ItemTypeIcon({ itemType }: { itemType: string }) {
  const size = 16;
  const props = { width: size, height: size, viewBox: '0 0 16 16', style: { flexShrink: 0 } as const };

  switch (itemType) {
    case 'book':
      return (
        <svg {...props} fill="none" stroke="#6b4c2a" strokeWidth="1.2">
          <rect x="2" y="1.5" width="12" height="13" rx="1" fill="#deb887" stroke="#6b4c2a"/>
          <line x1="5" y1="1.5" x2="5" y2="14.5"/>
          <line x1="7" y1="4.5" x2="12" y2="4.5" strokeWidth="1"/>
          <line x1="7" y1="7" x2="12" y2="7" strokeWidth="1"/>
          <line x1="7" y1="9.5" x2="10" y2="9.5" strokeWidth="1"/>
        </svg>
      );
    case 'bookSection':
      return (
        <svg {...props} fill="none" stroke="#6b4c2a" strokeWidth="1.2">
          <rect x="2" y="1.5" width="12" height="13" rx="1" fill="#deb887" stroke="#6b4c2a"/>
          <line x1="5" y1="1.5" x2="5" y2="14.5"/>
          <rect x="7" y="5" width="5" height="5" rx="0.5" fill="#fff3cd" stroke="#6b4c2a" strokeWidth="0.8"/>
        </svg>
      );
    case 'journalArticle':
      return (
        <svg {...props} fill="none" stroke="#2c5aa0" strokeWidth="1.2">
          <rect x="2" y="1" width="12" height="14" rx="1" fill="#e8f0fe"/>
          <line x1="4.5" y1="4" x2="11.5" y2="4" strokeWidth="1"/>
          <line x1="4.5" y1="6.5" x2="11.5" y2="6.5" strokeWidth="0.8" strokeOpacity="0.6"/>
          <line x1="4.5" y1="8.5" x2="11.5" y2="8.5" strokeWidth="0.8" strokeOpacity="0.6"/>
          <line x1="4.5" y1="10.5" x2="9" y2="10.5" strokeWidth="0.8" strokeOpacity="0.6"/>
          <line x1="2" y1="12" x2="14" y2="12" strokeWidth="1.5" stroke="#2c5aa0"/>
        </svg>
      );
    case 'magazineArticle':
    case 'newspaperArticle':
      return (
        <svg {...props} fill="none" stroke="#555" strokeWidth="1.2">
          <rect x="1.5" y="1" width="13" height="14" rx="1" fill="#f5f5f0"/>
          <line x1="4" y1="3.5" x2="12" y2="3.5" strokeWidth="1.5"/>
          <line x1="4" y1="6" x2="8" y2="6" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="4" y1="8" x2="8" y2="8" strokeWidth="0.8" strokeOpacity="0.5"/>
          <rect x="9" y="5.5" width="3.5" height="3" rx="0.3" fill="#ddd" stroke="#999" strokeWidth="0.6"/>
          <line x1="4" y1="10.5" x2="12" y2="10.5" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="4" y1="12.5" x2="10" y2="12.5" strokeWidth="0.8" strokeOpacity="0.5"/>
        </svg>
      );
    case 'conferencePaper':
      return (
        <svg {...props} fill="none" stroke="#7b2d8e" strokeWidth="1.2">
          <rect x="2" y="2" width="12" height="12" rx="1" fill="#f3e8f9"/>
          <circle cx="8" cy="6" r="2" fill="#d4a5e8" stroke="#7b2d8e" strokeWidth="0.8"/>
          <line x1="4.5" y1="10" x2="11.5" y2="10" strokeWidth="0.8"/>
          <line x1="4.5" y1="12" x2="9" y2="12" strokeWidth="0.8" strokeOpacity="0.6"/>
        </svg>
      );
    case 'thesis':
      return (
        <svg {...props} fill="none" stroke="#2a6b2a" strokeWidth="1.2">
          <rect x="3" y="1" width="10" height="14" rx="1" fill="#e8f5e8"/>
          <circle cx="8" cy="5" r="2.5" fill="none" stroke="#2a6b2a" strokeWidth="1"/>
          <line x1="8" y1="3.5" x2="8" y2="5" strokeWidth="0.8"/>
          <line x1="8" y1="5" x2="9.5" y2="5" strokeWidth="0.8"/>
          <line x1="5" y1="9" x2="11" y2="9" strokeWidth="0.8" strokeOpacity="0.6"/>
          <line x1="5" y1="11" x2="11" y2="11" strokeWidth="0.8" strokeOpacity="0.6"/>
          <line x1="5" y1="13" x2="9" y2="13" strokeWidth="0.8" strokeOpacity="0.6"/>
        </svg>
      );
    case 'webpage':
    case 'blogPost':
    case 'forumPost':
      return (
        <svg {...props} fill="none" stroke="#0969da" strokeWidth="1.2">
          <rect x="1.5" y="2" width="13" height="12" rx="1.5" fill="#e8f4fd"/>
          <line x1="1.5" y1="5" x2="14.5" y2="5" strokeWidth="1"/>
          <circle cx="3.5" cy="3.5" r="0.7" fill="#ff6b6b" stroke="none"/>
          <circle cx="5.5" cy="3.5" r="0.7" fill="#ffd93d" stroke="none"/>
          <circle cx="7.5" cy="3.5" r="0.7" fill="#6bcb77" stroke="none"/>
          <line x1="4" y1="7.5" x2="12" y2="7.5" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="4" y1="9.5" x2="10" y2="9.5" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="4" y1="11.5" x2="8" y2="11.5" strokeWidth="0.8" strokeOpacity="0.5"/>
        </svg>
      );
    case 'report':
      return (
        <svg {...props} fill="none" stroke="#8b6914" strokeWidth="1.2">
          <rect x="3" y="1" width="10" height="14" rx="1" fill="#fef9e7"/>
          <line x1="5.5" y1="4" x2="10.5" y2="4" strokeWidth="1"/>
          <line x1="5.5" y1="6.5" x2="10.5" y2="6.5" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="5.5" y1="8.5" x2="10.5" y2="8.5" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="5.5" y1="10.5" x2="8.5" y2="10.5" strokeWidth="0.8" strokeOpacity="0.5"/>
          <polyline points="3,4 1,4 1,13 3,13" strokeWidth="1" fill="none"/>
        </svg>
      );
    case 'patent':
      return (
        <svg {...props} fill="none" stroke="#b8860b" strokeWidth="1.2">
          <rect x="3" y="2" width="10" height="12" rx="1" fill="#fff8dc"/>
          <circle cx="8" cy="6.5" r="2.5" fill="none" stroke="#b8860b"/>
          <line x1="8" y1="9" x2="8" y2="11.5" strokeWidth="1.5"/>
          <line x1="6.5" y1="10.5" x2="9.5" y2="10.5" strokeWidth="1"/>
        </svg>
      );
    case 'letter':
    case 'email':
      return (
        <svg {...props} fill="none" stroke="#666" strokeWidth="1.2">
          <rect x="1.5" y="3" width="13" height="10" rx="1" fill="#f0f0f0"/>
          <polyline points="1.5,3 8,9 14.5,3" strokeWidth="1.2"/>
        </svg>
      );
    case 'film':
    case 'videoRecording':
    case 'tvBroadcast':
      return (
        <svg {...props} fill="none" stroke="#c0392b" strokeWidth="1.2">
          <rect x="1.5" y="3" width="13" height="10" rx="1.5" fill="#fdecea"/>
          <polygon points="6.5,5.5 6.5,10.5 11.5,8" fill="#c0392b" stroke="none"/>
        </svg>
      );
    case 'audioRecording':
    case 'podcast':
    case 'radioBroadcast':
      return (
        <svg {...props} fill="none" stroke="#8e44ad" strokeWidth="1.2">
          <circle cx="8" cy="8" r="6" fill="#f5eef8"/>
          <circle cx="8" cy="8" r="2" fill="#8e44ad" stroke="none"/>
          <circle cx="8" cy="8" r="4" fill="none" strokeOpacity="0.4"/>
        </svg>
      );
    case 'artwork':
      return (
        <svg {...props} fill="none" stroke="#c0392b" strokeWidth="1.2">
          <rect x="2" y="2" width="12" height="12" rx="0.5" fill="#fff5f5"/>
          <rect x="3.5" y="3.5" width="9" height="9" rx="0" fill="none" stroke="#c0392b" strokeWidth="0.8"/>
          <circle cx="6" cy="7" r="1.5" fill="#ffd93d" stroke="none"/>
          <polyline points="4,11.5 7,8 9,10 10.5,8.5 12,11.5" stroke="#6bcb77" strokeWidth="0.8" fill="none"/>
        </svg>
      );
    case 'map':
      return (
        <svg {...props} fill="none" stroke="#27ae60" strokeWidth="1.2">
          <polygon points="1,2 6,3.5 11,2 15,3.5 15,14 11,12.5 6,14 1,12.5" fill="#e8f8f0"/>
          <line x1="6" y1="3.5" x2="6" y2="14"/>
          <line x1="11" y1="2" x2="11" y2="12.5"/>
        </svg>
      );
    case 'presentation':
      return (
        <svg {...props} fill="none" stroke="#e67e22" strokeWidth="1.2">
          <rect x="2" y="1.5" width="12" height="9" rx="1" fill="#fef5e7"/>
          <line x1="8" y1="10.5" x2="8" y2="13"/>
          <line x1="5" y1="13" x2="11" y2="13" strokeWidth="1.5"/>
          <rect x="4.5" y="4" width="7" height="4" rx="0.5" fill="none" stroke="#e67e22" strokeWidth="0.8"/>
        </svg>
      );
    case 'computerProgram':
      return (
        <svg {...props} fill="none" stroke="#2c3e50" strokeWidth="1.2">
          <rect x="2" y="2" width="12" height="12" rx="1.5" fill="#ecf0f1"/>
          <polyline points="5,6 3.5,8 5,10" strokeWidth="1.2"/>
          <polyline points="11,6 12.5,8 11,10" strokeWidth="1.2"/>
          <line x1="7" y1="11" x2="9" y2="5" strokeWidth="1" strokeOpacity="0.6"/>
        </svg>
      );
    case 'statute':
    case 'bill':
    case 'case':
    case 'hearing':
      return (
        <svg {...props} fill="none" stroke="#5d6d7e" strokeWidth="1.2">
          <rect x="3" y="1" width="10" height="14" rx="1" fill="#eaecee"/>
          <text x="8" y="10.5" textAnchor="middle" fontSize="8" fill="#5d6d7e" stroke="none" fontWeight="bold">§</text>
        </svg>
      );
    case 'interview':
      return (
        <svg {...props} fill="none" stroke="#2980b9" strokeWidth="1.2">
          <circle cx="6" cy="6" r="3" fill="#d6eaf8"/>
          <circle cx="11" cy="7" r="2.5" fill="#d5f5e3" stroke="#27ae60"/>
          <path d="M4,9 Q3,12 1,12" strokeWidth="1"/>
          <path d="M12.5,9.5 Q13.5,11.5 15,11.5" strokeWidth="1" stroke="#27ae60"/>
        </svg>
      );
    case 'manuscript':
      return (
        <svg {...props} fill="none" stroke="#8b6914" strokeWidth="1.2">
          <rect x="3" y="1" width="10" height="14" rx="1" fill="#fdf6e3"/>
          <line x1="5.5" y1="4" x2="10.5" y2="4" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="5.5" y1="6" x2="10.5" y2="6" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="5.5" y1="8" x2="10.5" y2="8" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="5.5" y1="10" x2="10.5" y2="10" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="5.5" y1="12" x2="8.5" y2="12" strokeWidth="0.8" strokeOpacity="0.5"/>
        </svg>
      );
    case 'encyclopediaArticle':
    case 'dictionaryEntry':
      return (
        <svg {...props} fill="none" stroke="#6b4c2a" strokeWidth="1.2">
          <rect x="1.5" y="1.5" width="13" height="13" rx="1" fill="#deb887"/>
          <line x1="4.5" y1="1.5" x2="4.5" y2="14.5"/>
          <text x="9.5" y="10.5" textAnchor="middle" fontSize="7" fill="#6b4c2a" stroke="none" fontWeight="bold">A</text>
        </svg>
      );
    case 'note':
      return (
        <svg {...props} fill="none" stroke="#f39c12" strokeWidth="1.2">
          <rect x="2" y="1" width="12" height="14" rx="1" fill="#fef9e7"/>
          <line x1="4.5" y1="4" x2="11.5" y2="4" strokeWidth="0.8"/>
          <line x1="4.5" y1="6.5" x2="11.5" y2="6.5" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="4.5" y1="9" x2="11.5" y2="9" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="4.5" y1="11.5" x2="8" y2="11.5" strokeWidth="0.8" strokeOpacity="0.5"/>
        </svg>
      );
    default: // document and other types
      return (
        <svg {...props} fill="none" stroke="#888" strokeWidth="1.2">
          <path d="M4,1 L11,1 L13,3 L13,15 L3,15 L3,1 Z" fill="#f5f5f5"/>
          <polyline points="11,1 11,3 13,3" fill="none"/>
          <line x1="5.5" y1="5.5" x2="10.5" y2="5.5" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="5.5" y1="7.5" x2="10.5" y2="7.5" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="5.5" y1="9.5" x2="10.5" y2="9.5" strokeWidth="0.8" strokeOpacity="0.5"/>
          <line x1="5.5" y1="11.5" x2="8.5" y2="11.5" strokeWidth="0.8" strokeOpacity="0.5"/>
        </svg>
      );
  }
}

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
                <td className="item-title">
                  <span className="item-title-content">
                    <ItemTypeIcon itemType={item.data.itemType} />
                    {item.data.title || '(Untitled)'}
                  </span>
                </td>
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
