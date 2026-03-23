import { useState, useEffect } from 'react';
import type { UserInfo } from './types/zotero';
import { login, verifySession, logout } from './api/zotero';
import LoginPage from './components/LoginPage';
import LibraryView from './components/LibraryView';

export default function App() {
  const [userInfo, setUserInfo] = useState<UserInfo | null>(null);
  const [checking, setChecking] = useState(true);

  useEffect(() => {
    verifySession()
      .then(setUserInfo)
      .finally(() => setChecking(false));
  }, []);

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
