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
        <a href="/groups.php">Groups</a>
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
