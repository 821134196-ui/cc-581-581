import { Navigate, NavLink, Route, Routes } from 'react-router-dom'
import { useAuth } from './auth.jsx'
import LoginPage from './pages/LoginPage.jsx'
import NewApplicationPage from './pages/NewApplicationPage.jsx'
import MyApplicationsPage from './pages/MyApplicationsPage.jsx'
import ApplicationDetailPage from './pages/ApplicationDetailPage.jsx'
import SmsInboxPage from './pages/SmsInboxPage.jsx'
import CommunityQueuePage from './pages/CommunityQueuePage.jsx'
import StreetQueuePage from './pages/StreetQueuePage.jsx'
import RosterPage from './pages/RosterPage.jsx'
import StaffApprovalsPage from './pages/StaffApprovalsPage.jsx'
import BatchesPage from './pages/BatchesPage.jsx'

const ROLE_NAME = { resident: '居民', community: '社区初审员', street: '街道复核员' }

export default function App() {
  const { user, loading, logout } = useAuth()

  if (loading) return <div className="empty">加载中…</div>
  if (!user) return <LoginPage />

  return (
    <div className="layout">
      <header className="topbar">
        <span className="brand">❄️ 低温取暖补贴系统</span>
        <nav>
          {user.role === 'resident' && <>
            <NavLink to="/apply">申请补贴</NavLink>
            <NavLink to="/my">我的申请</NavLink>
          </>}
          {user.role === 'community' && <NavLink to="/community">社区初审</NavLink>}
          {user.role === 'street' && <>
            <NavLink to="/street">街道复核</NavLink>
            <NavLink to="/roster">发放清单</NavLink>
            <NavLink to="/batches">预警批次</NavLink>
          </>}
          {(user.role === 'community' || user.role === 'street') && (
            <NavLink to="/staff/approvals">待办（变更/申诉）</NavLink>
          )}
          <NavLink to="/sms">短信箱</NavLink>
        </nav>
        <span className="spacer" />
        <span className="role-tag">{ROLE_NAME[user.role]}</span>
        <span>{user.name}</span>
        <button className="link" onClick={logout}>退出</button>
      </header>

      <main className="content">
        <Routes>
          <Route path="/" element={
            <Navigate to={user.role === 'resident' ? '/my' : user.role === 'community' ? '/community' : '/street'} />
          } />
          <Route path="/apply" element={<NewApplicationPage />} />
          <Route path="/my" element={<MyApplicationsPage />} />
          <Route path="/applications/:id" element={<ApplicationDetailPage />} />
          <Route path="/sms" element={<SmsInboxPage />} />
          <Route path="/community" element={<CommunityQueuePage />} />
          <Route path="/street" element={<StreetQueuePage />} />
          <Route path="/roster" element={<RosterPage />} />
          <Route path="/staff/approvals" element={<StaffApprovalsPage />} />
          <Route path="/batches" element={<BatchesPage />} />
          <Route path="*" element={<Navigate to="/" />} />
        </Routes>
      </main>
    </div>
  )
}
