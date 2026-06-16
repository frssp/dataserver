import { useState } from 'react';

interface Props {
  onSearch: (query: string) => void;
  username?: string;
  onLogout?: () => void;
  publicMode?: boolean;
}

export default function SearchBar({ onSearch, username, onLogout, publicMode }: Props) {
  const [query, setQuery] = useState('');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSearch(query);
  };

  const handleClear = () => {
    setQuery('');
    onSearch('');
  };

  return (
    <nav className="site-nav">
      <a href="/" className="nav-logo">
        <span className="z">z</span>
        <span className="rest">otero</span>
      </a>
      <div className="nav-links">
        <a href="/library/" className="active">Web Library</a>
        <div className="nav-item">
          <button type="button" className="nav-trigger">
            Groups
            <svg className="caret" viewBox="0 0 12 12" fill="none" aria-hidden="true">
              <path d="M3 4.5 6 7.5 9 4.5" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </button>
          <div className="dropdown">
            <a href="/groups.php">Group Search<span className="d-desc">Find public groups</span></a>
            <a href="/library/">Group Library<span className="d-desc">Your group libraries</span></a>
          </div>
        </div>
        {!publicMode && <a href="/account.php">Account</a>}
        <a href="/manual.html">User Guide</a>
      </div>
      <form className="nav-search" onSubmit={handleSubmit}>
        <input
          type="text"
          placeholder="Title, Creator, Year"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
        {query && (
          <button type="button" className="search-clear" onClick={handleClear}>
            &times;
          </button>
        )}
      </form>
      <div className="nav-right">
        {publicMode ? (
          <a href="/library/" className="nav-admin">Sign in</a>
        ) : (
          <>
            <a href="/admin.php" className="nav-admin">Admin</a>
            <span className="nav-user">{username}</span>
            <button className="nav-logout" onClick={onLogout}>Log Out</button>
          </>
        )}
      </div>
    </nav>
  );
}
