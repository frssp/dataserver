import { useState, useEffect, useCallback } from 'react';
import type { ZoteroItem, ZoteroCollection, ZoteroTag, UserInfo, LibraryContext } from '../types/zotero';
import { fetchCollections, fetchItems, fetchTags } from '../api/zotero';
import SearchBar from './SearchBar';
import CollectionTree from './CollectionTree';
import ItemsTable from './ItemsTable';
import ItemDetail from './ItemDetail';
import TagFilter from './TagFilter';

interface Props {
  userInfo: UserInfo;
  onLogout: () => void;
}

const PAGE_SIZE = 50;

export default function LibraryView({ userInfo, onLogout }: Props) {
  const [library, setLibrary] = useState<LibraryContext>({
    type: 'user',
    id: userInfo.userID,
    name: 'My Library',
  });

  const [collections, setCollections] = useState<ZoteroCollection[]>([]);
  const [items, setItems] = useState<ZoteroItem[]>([]);
  const [totalResults, setTotalResults] = useState(0);
  const [tags, setTags] = useState<ZoteroTag[]>([]);
  const [selectedCollection, setSelectedCollection] = useState<string | null>(null);
  const [selectedItemKey, setSelectedItemKey] = useState<string | null>(null);
  const [selectedTags, setSelectedTags] = useState<string[]>([]);
  const [searchQuery, setSearchQuery] = useState('');
  const [sortField, setSortField] = useState('title');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);

  // Load collections and tags when library changes
  useEffect(() => {
    setCollections([]);
    setTags([]);
    setSelectedCollection(null);
    setSelectedItemKey(null);
    setSelectedTags([]);
    setPage(1);

    fetchCollections(library.type, library.id)
      .then(setCollections)
      .catch(console.error);

    fetchTags(library.type, library.id)
      .then(setTags)
      .catch(console.error);
  }, [library.type, library.id]);

  // Load items when filters change
  const loadItems = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, string> = {
        limit: String(PAGE_SIZE),
        start: String((page - 1) * PAGE_SIZE),
        sort: sortField,
        direction: sortDirection,
        itemType: '-attachment || note',
      };
      if (searchQuery) {
        params.q = searchQuery;
        params.qmode = 'titleCreatorYear';
      }
      if (selectedTags.length > 0) {
        params.tag = selectedTags.join(' || ');
      }
      const result = await fetchItems(library.type, library.id, params, selectedCollection);
      setItems(result.items);
      setTotalResults(result.totalResults);
    } catch (err) {
      console.error('Failed to load items:', err);
      setItems([]);
      setTotalResults(0);
    } finally {
      setLoading(false);
    }
  }, [library.type, library.id, page, sortField, sortDirection, selectedCollection, searchQuery, selectedTags]);

  useEffect(() => {
    loadItems();
  }, [loadItems]);

  const handleSort = (field: string) => {
    if (field === sortField) {
      setSortDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
    setPage(1);
  };

  const handleSearch = (query: string) => {
    setSearchQuery(query);
    setPage(1);
    setSelectedItemKey(null);
  };

  const handleSelectCollection = (key: string | null) => {
    setSelectedCollection(key);
    setPage(1);
    setSelectedItemKey(null);
  };

  const handleToggleTag = (tag: string) => {
    setSelectedTags((prev) =>
      prev.includes(tag) ? prev.filter((t) => t !== tag) : [...prev, tag],
    );
    setPage(1);
  };

  const handleChangeLibrary = (lib: LibraryContext) => {
    setLibrary(lib);
  };

  const selectedItem = items.find((i) => i.key === selectedItemKey) || null;

  return (
    <div className="library-view">
      <SearchBar onSearch={handleSearch} username={userInfo.username} onLogout={onLogout} />
      <div className="library-body">
        <div className="left-panel">
          <CollectionTree
            collections={collections}
            library={library}
            userInfo={userInfo}
            selectedCollection={selectedCollection}
            onSelectCollection={handleSelectCollection}
            onChangeLibrary={handleChangeLibrary}
          />
          <TagFilter
            tags={tags}
            selectedTags={selectedTags}
            onToggleTag={handleToggleTag}
          />
        </div>
        <div className="center-panel">
          <div className="library-breadcrumb">
            <span>{library.name}</span>
            {selectedCollection && (
              <>
                <span className="breadcrumb-sep">/</span>
                <span>
                  {collections.find((c) => c.key === selectedCollection)?.data.name || ''}
                </span>
              </>
            )}
            {searchQuery && <span className="breadcrumb-search">Search: "{searchQuery}"</span>}
          </div>
          <ItemsTable
            items={items}
            totalResults={totalResults}
            page={page}
            pageSize={PAGE_SIZE}
            sortField={sortField}
            sortDirection={sortDirection}
            selectedItemKey={selectedItemKey}
            onSelectItem={setSelectedItemKey}
            onSort={handleSort}
            onPageChange={setPage}
            loading={loading}
          />
        </div>
        <div className="right-panel">
          <ItemDetail item={selectedItem} />
        </div>
      </div>
    </div>
  );
}
