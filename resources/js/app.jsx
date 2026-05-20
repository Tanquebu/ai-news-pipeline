import './bootstrap';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Link } from 'react-router-dom';
import ClusterFeed from './components/ClusterFeed';
import ClusterDetail from './components/ClusterDetail';
import PublicationsList from './components/PublicationsList';
import ReportsList from './components/ReportsList';

function Nav() {
    return (
        <nav className="bg-white border-b px-6 py-3 flex gap-6 items-center">
            <span className="font-bold text-gray-800">AI News Pipeline</span>
            <Link to="/" className="text-sm text-blue-600 hover:underline">Feed</Link>
            <Link to="/publications" className="text-sm text-blue-600 hover:underline">Pubblicazioni</Link>
            <Link to="/reports" className="text-sm text-blue-600 hover:underline">Report</Link>
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
