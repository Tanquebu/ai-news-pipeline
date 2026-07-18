import './bootstrap';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, NavLink } from 'react-router-dom';
import ClusterFeed from './components/ClusterFeed';
import ClusterDetail from './components/ClusterDetail';
import PublicationsList from './components/PublicationsList';
import ReportsList from './components/ReportsList';

function NavTab({ to, end, children }) {
    return (
        <NavLink
            to={to}
            end={end}
            className={({ isActive }) =>
                `text-sm pb-0.5 border-b-2 ${isActive
                    ? 'text-primary font-medium border-primary'
                    : 'text-fg-secondary border-transparent hover:text-fg'}`
            }
        >
            {children}
        </NavLink>
    );
}

function Nav() {
    return (
        <nav className="bg-surface border-b border-border px-6 py-3 flex gap-6 items-center">
            <span className="font-bold text-fg">AI News Pipeline</span>
            <NavTab to="/" end>Feed</NavTab>
            <NavTab to="/publications">Pubblicazioni</NavTab>
            <NavTab to="/reports">Report</NavTab>
        </nav>
    );
}

function App() {
    return (
        <BrowserRouter>
            <Nav />
            <main>
                <Routes>
                    <Route path="/" element={<ClusterFeed />} />
                    <Route path="/clusters/:id" element={<ClusterDetail />} />
                    <Route path="/publications" element={<PublicationsList />} />
                    <Route path="/reports" element={<ReportsList />} />
                </Routes>
            </main>
        </BrowserRouter>
    );
}

createRoot(document.getElementById('app')).render(<App />);
