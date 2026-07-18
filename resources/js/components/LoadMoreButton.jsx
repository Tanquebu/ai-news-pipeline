export default function LoadMoreButton({ hasMore, loading, onClick }) {
    if (!hasMore) return null;

    return (
        <div className="mt-4 flex justify-center">
            <button
                onClick={onClick}
                disabled={loading}
                className="text-sm px-4 py-2 rounded-lg border border-border hover:bg-surface-muted disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                {loading ? 'Caricamento…' : 'Carica altro'}
            </button>
        </div>
    );
}
