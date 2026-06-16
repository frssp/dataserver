import { useState, useEffect } from 'react';
import type { UserInfo } from './types/zotero';
import { login, verifySession, logout } from './api/zotero';
import LoginPage from './components/LoginPage';
import LibraryView from './components/LibraryView';

export default function App() {
  // Public group view: /library/?group=<id>&name=<name> — no login required.
  const params = new URLSearchParams(window.location.search);
  const groupParam = params.get('group');

  const [userInfo, setUserInfo] = useState<UserInfo | null>(null);
  const [checking, setChecking] = useState(!groupParam);

  useEffect(() => {
    if (groupParam) return; // public group mode skips the session check
    verifySession()
      .then(setUserInfo)
      .finally(() => setChecking(false));
  }, [groupParam]);

  if (groupParam) {
    const gid = parseInt(groupParam, 10);
    const gname = params.get('name') || `Group ${gid}`;
    return <LibraryView publicGroup={{ id: gid, name: gname }} />;
  }

  const handleLogin = async (username: string, password: string) => {
    const info = await login(username, password);
    setUserInfo(info);
  };

  const handleLogout = () => {
    logout();
    setUserInfo(null);
  };

  if (checking) {
    return (
      <div className="app-loading">
        <div className="spinner" />
        <p>Loading...</p>
      </div>
    );
  }

  if (!userInfo) {
    return <LoginPage onLogin={handleLogin} />;
  }

  return <LibraryView userInfo={userInfo} onLogout={handleLogout} />;
}
