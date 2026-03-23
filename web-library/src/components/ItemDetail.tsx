import { useState } from 'react';
import type { ZoteroItem } from '../types/zotero';

interface Props {
  item: ZoteroItem | null;
}

type Tab = 'info' | 'notes' | 'tags';

const DISPLAY_FIELDS: { key: string; label: string }[] = [
  { key: 'itemType', label: 'Item Type' },
  { key: 'title', label: 'Title' },
  { key: 'publicationTitle', label: 'Publication' },
  { key: 'publisher', label: 'Publisher' },
  { key: 'place', label: 'Place' },
  { key: 'date', label: 'Date' },
  { key: 'volume', label: 'Volume' },
  { key: 'issue', label: 'Issue' },
  { key: 'pages', label: 'Pages' },
  { key: 'series', label: 'Series' },
  { key: 'seriesTitle', label: 'Series Title' },
  { key: 'seriesText', label: 'Series Text' },
  { key: 'journalAbbreviation', label: 'Journal Abbr' },
  { key: 'DOI', label: 'DOI' },
  { key: 'ISSN', label: 'ISSN' },
  { key: 'ISBN', label: 'ISBN' },
  { key: 'url', label: 'URL' },
  { key: 'abstractNote', label: 'Abstract' },
  { key: 'accessDate', label: 'Accessed' },
  { key: 'extra', label: 'Extra' },
];

function formatCreatorName(creator: {
  creatorType: string;
  firstName?: string;
  lastName?: string;
  name?: string;
}): string {
  if (creator.name) return creator.name;
  return [creator.lastName, creator.firstName].filter(Boolean).join(', ');
}

export default function ItemDetail({ item }: Props) {
  const [activeTab, setActiveTab] = useState<Tab>('info');

  if (!item) {
    return (
      <div className="item-detail empty">
        <p>Select an item to view details</p>
      </div>
    );
  }

  const tabs: { key: Tab; label: string }[] = [
    { key: 'info', label: 'Info' },
    { key: 'notes', label: 'Notes' },
    { key: 'tags', label: 'Tags' },
  ];

  return (
    <div className="item-detail">
      <div className="detail-tabs">
        {tabs.map((tab) => (
          <button
            key={tab.key}
            className={`detail-tab ${activeTab === tab.key ? 'active' : ''}`}
            onClick={() => setActiveTab(tab.key)}
          >
            {tab.label}
          </button>
        ))}
      </div>

      <div className="detail-content">
        {activeTab === 'info' && (
          <div className="detail-info">
            {/* Creators */}
            {item.data.creators && item.data.creators.length > 0 && (
              <div className="detail-section">
                {item.data.creators.map((c, i) => (
                  <div key={i} className="detail-row">
                    <span className="detail-label">
                      {c.creatorType.charAt(0).toUpperCase() + c.creatorType.slice(1)}
                    </span>
                    <span className="detail-value">{formatCreatorName(c)}</span>
                  </div>
                ))}
              </div>
            )}
            {/* Fields */}
            {DISPLAY_FIELDS.map((field) => {
              const val = item.data[field.key];
              if (!val || (typeof val === 'string' && !val.trim())) return null;
              return (
                <div key={field.key} className="detail-row">
                  <span className="detail-label">{field.label}</span>
                  <span className="detail-value">
                    {field.key === 'DOI' ? (
                      <a href={`https://doi.org/${val}`} target="_blank" rel="noreferrer">
                        {val}
                      </a>
                    ) : field.key === 'url' ? (
                      <a href={val} target="_blank" rel="noreferrer">
                        {val}
                      </a>
                    ) : (
                      String(val)
                    )}
                  </span>
                </div>
              );
            })}
            {/* Date Added / Modified */}
            <div className="detail-row">
              <span className="detail-label">Date Added</span>
              <span className="detail-value">
                {new Date(item.data.dateAdded).toLocaleDateString()}
              </span>
            </div>
            <div className="detail-row">
              <span className="detail-label">Date Modified</span>
              <span className="detail-value">
                {new Date(item.data.dateModified).toLocaleDateString()}
              </span>
            </div>
          </div>
        )}

        {activeTab === 'notes' && (
          <div className="detail-notes">
            {item.data.note ? (
              <div
                className="note-content"
                dangerouslySetInnerHTML={{ __html: item.data.note }}
              />
            ) : (
              <p className="empty-message">No notes</p>
            )}
          </div>
        )}

        {activeTab === 'tags' && (
          <div className="detail-tags">
            {item.data.tags && item.data.tags.length > 0 ? (
              <div className="tag-list">
                {item.data.tags.map((t, i) => (
                  <span key={i} className="tag-badge">
                    {t.tag}
                  </span>
                ))}
              </div>
            ) : (
              <p className="empty-message">No tags</p>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
