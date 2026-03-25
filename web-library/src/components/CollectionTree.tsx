import { useState, useMemo, useRef, useEffect } from 'react';
import type { ZoteroCollection, LibraryContext, UserInfo } from '../types/zotero';

// SVG Icons
function LibraryIcon() {
  return (
    <svg className="tree-svg-icon" viewBox="0 0 16 16" width="14" height="14">
      <path d="M1 14h14v1H1zM2 3h1v11H2zM5 3h1v11H5zM8 3h1v11H8zM11 3h1v11h-1zM13 3h1v11h-1zM1 2h14v1H1zM3 1h10l1 1H2z" fill="#6d7b8d"/>
    </svg>
  );
}

function GroupIcon({ type }: { type: string }) {
  const color = type === 'Private' ? '#b07a38' : type === 'PublicClosed' ? '#4a7fb5' : '#5a9e4b';
  return (
    <svg className="tree-svg-icon" viewBox="0 0 16 16" width="14" height="14">
      <path d="M1 14h14v1H1zM2 3h1v11H2zM5 3h1v11H5zM8 3h1v11H8zM11 3h1v11h-1zM13 3h1v11h-1zM1 2h14v1H1zM3 1h10l1 1H2z" fill={color}/>
    </svg>
  );
}

function FolderIcon() {
  return (
    <svg className="tree-svg-icon" viewBox="0 0 16 16" width="14" height="14">
      <path d="M1 3h5l1 1h7v10H1V3z" fill="none" stroke="#6d7b8d" strokeWidth="1.2"/>
    </svg>
  );
}

// Context menu for export
function ContextMenu({
  x,
  y,
  onExport,
  onClose,
}: {
  x: number;
  y: number;
  onExport: (format: string) => void;
  onClose: () => void;
}) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) onClose();
    }
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [onClose]);

  const formats = [
    { key: 'bibtex', label: 'BibTeX (.bib)' },
    { key: 'biblatex', label: 'BibLaTeX (.bib)' },
    { key: 'ris', label: 'RIS (.ris)' },
    { key: 'csljson', label: 'CSL JSON (.json)' },
    { key: 'csv', label: 'CSV (.csv)' },
  ];

  return (
    <div className="tree-context-menu" style={{ top: y, left: x }} ref={ref}>
      <div className="tree-menu-header">Export as...</div>
      {formats.map((f) => (
        <div key={f.key} className="tree-menu-item" onClick={() => { onExport(f.key); onClose(); }}>
          {f.label}
        </div>
      ))}
    </div>
  );
}

function getExportUrl(
  libType: 'user' | 'group',
  libId: number,
  collectionKey: string | null,
  format: string,
  apiKey: string,
): string {
  const prefix = libType === 'user' ? `/users/${libId}` : `/groups/${libId}`;
  const params = new URLSearchParams({ format, key: apiKey, limit: '10000' });
  if (collectionKey) {
    return `${prefix}/items?collectionKey=${collectionKey}&${params}`;
  }
  return `${prefix}/items?${params}`;
}

interface Props {
  collections: ZoteroCollection[];
  library: LibraryContext;
  userInfo: UserInfo;
  selectedCollection: string | null;
  onSelectCollection: (key: string | null) => void;
  onChangeLibrary: (lib: LibraryContext) => void;
}

interface TreeNode {
  collection: ZoteroCollection;
  children: TreeNode[];
}

function buildTree(collections: ZoteroCollection[]): TreeNode[] {
  const map = new Map<string, TreeNode>();
  const roots: TreeNode[] = [];

  for (const c of collections) {
    map.set(c.key, { collection: c, children: [] });
  }

  for (const c of collections) {
    const node = map.get(c.key)!;
    if (c.data.parentCollection && map.has(c.data.parentCollection as string)) {
      map.get(c.data.parentCollection as string)!.children.push(node);
    } else {
      roots.push(node);
    }
  }

  const sort = (nodes: TreeNode[]) => {
    nodes.sort((a, b) => a.collection.data.name.localeCompare(b.collection.data.name));
    nodes.forEach((n) => sort(n.children));
  };
  sort(roots);
  return roots;
}

function DotsButton({ onClick }: { onClick: (e: React.MouseEvent) => void }) {
  return (
    <button className="tree-dots" onClick={onClick} title="More actions">
      &middot;&middot;&middot;
    </button>
  );
}

function TreeItem({
  node,
  depth,
  selectedKey,
  onSelect,
  onExport,
}: {
  node: TreeNode;
  depth: number;
  selectedKey: string | null;
  onSelect: (key: string) => void;
  onExport: (collectionKey: string, e: React.MouseEvent) => void;
}) {
  const [expanded, setExpanded] = useState(false);
  const hasChildren = node.children.length > 0;

  return (
    <>
      <div
        className={`tree-item ${selectedKey === node.collection.key ? 'selected' : ''}`}
        style={{ paddingLeft: 8 + depth * 16 }}
        onClick={() => onSelect(node.collection.key)}
      >
        {hasChildren ? (
          <span
            className="tree-toggle"
            onClick={(e) => { e.stopPropagation(); setExpanded(!expanded); }}
          >
            {expanded ? '▾' : '▸'}
          </span>
        ) : (
          <span className="tree-toggle" />
        )}
        <span className="tree-icon"><FolderIcon /></span>
        <span className="tree-name">{node.collection.data.name}</span>
        <span className="tree-count">{node.collection.meta.numItems}</span>
        <DotsButton onClick={(e) => { e.stopPropagation(); onExport(node.collection.key, e); }} />
      </div>
      {expanded &&
        node.children.map((child) => (
          <TreeItem
            key={child.collection.key}
            node={child}
            depth={depth + 1}
            selectedKey={selectedKey}
            onSelect={onSelect}
            onExport={onExport}
          />
        ))}
    </>
  );
}

export default function CollectionTree({
  collections,
  library,
  userInfo,
  selectedCollection,
  onSelectCollection,
  onChangeLibrary,
}: Props) {
  const tree = useMemo(() => buildTree(collections), [collections]);
  const [groupsExpanded, setGroupsExpanded] = useState(true);
  const [menu, setMenu] = useState<{ x: number; y: number; libType: 'user' | 'group'; libId: number; collectionKey: string | null } | null>(null);

  const apiKey = userInfo.apiKey;

  const handleExportClick = (libType: 'user' | 'group', libId: number, collectionKey: string | null, e: React.MouseEvent) => {
    const rect = (e.target as HTMLElement).getBoundingClientRect();
    setMenu({ x: rect.right + 4, y: rect.top, libType, libId, collectionKey });
  };

  const [exporting, setExporting] = useState(false);

  const handleExport = async (format: string) => {
    if (!menu || exporting) return;
    const url = getExportUrl(menu.libType, menu.libId, menu.collectionKey, format, apiKey);
    const ext = format === 'bibtex' || format === 'biblatex' ? 'bib' : format === 'csljson' ? 'json' : format;
    setExporting(true);
    try {
      const resp = await fetch(url);
      if (!resp.ok) {
        const text = await resp.text().catch(() => '');
        throw new Error(text || `HTTP ${resp.status}`);
      }
      const blob = await resp.blob();
      const blobUrl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = blobUrl;
      a.download = `export.${ext}`;
      a.click();
      URL.revokeObjectURL(blobUrl);
    } catch (err: any) {
      alert(`Export failed: ${err.message || 'Unknown error'}\n\nThe translation server may not be running.`);
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="collection-tree">
      {/* My Library */}
      <div
        className={`tree-item library-root ${library.type === 'user' && !selectedCollection ? 'selected' : ''}`}
        onClick={() => {
          onChangeLibrary({ type: 'user', id: userInfo.userID, name: 'My Library' });
          onSelectCollection(null);
        }}
      >
        <span className="tree-toggle" />
        <span className="tree-icon"><LibraryIcon /></span>
        <span className="tree-name">My Library</span>
        <DotsButton onClick={(e) => { e.stopPropagation(); handleExportClick('user', userInfo.userID, null, e); }} />
      </div>

      {/* Collections */}
      {library.type === 'user' &&
        tree.map((node) => (
          <TreeItem
            key={node.collection.key}
            node={node}
            depth={1}
            selectedKey={selectedCollection}
            onSelect={onSelectCollection}
            onExport={(colKey, e) => handleExportClick('user', userInfo.userID, colKey, e)}
          />
        ))}

      {/* Group Libraries */}
      {userInfo.groups.length > 0 && (
        <>
          <div
            className="tree-item library-root group-header"
            onClick={() => setGroupsExpanded(!groupsExpanded)}
          >
            <span className="tree-toggle">{groupsExpanded ? '▾' : '▸'}</span>
            <span className="tree-icon" style={{ opacity: 0.6 }}>▾</span>
            <span className="tree-name">Group Libraries</span>
          </div>
          {groupsExpanded &&
            userInfo.groups.map((g) => (
              <div key={g.groupID}>
                <div
                  className={`tree-item group-item ${library.type === 'group' && library.id === g.groupID ? 'selected' : ''}`}
                  style={{ paddingLeft: 24 }}
                  onClick={() => {
                    onChangeLibrary({ type: 'group', id: g.groupID, name: g.name });
                    onSelectCollection(null);
                  }}
                >
                  <span className="tree-toggle" />
                  <span className="tree-icon"><GroupIcon type={g.type} /></span>
                  <span className="tree-name">{g.name}</span>
                  <DotsButton onClick={(e) => { e.stopPropagation(); handleExportClick('group', g.groupID, null, e); }} />
                </div>
                {library.type === 'group' &&
                  library.id === g.groupID &&
                  tree.map((node) => (
                    <TreeItem
                      key={node.collection.key}
                      node={node}
                      depth={2}
                      selectedKey={selectedCollection}
                      onSelect={onSelectCollection}
                      onExport={(colKey, e) => handleExportClick('group', g.groupID, colKey, e)}
                    />
                  ))}
              </div>
            ))}
        </>
      )}

      {/* Context Menu */}
      {menu && (
        <ContextMenu
          x={menu.x}
          y={menu.y}
          onExport={handleExport}
          onClose={() => setMenu(null)}
        />
      )}
    </div>
  );
}
