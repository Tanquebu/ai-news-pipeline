import { useRef, useState } from 'react';

export function usePaginatedList(fetcher) {
    const [items, setItems] = useState([]);
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(true);
    const [loadingMore, setLoadingMore] = useState(false);
    const [error, setError] = useState(null);
    const lastParams = useRef({});

    const load = (params = {}) => {
        lastParams.current = params;
        setLoading(true);
        setError(null);
        fetcher({ ...params, page: 1 })
            .then((data) => {
                setItems(data.data ?? []);
                setPage(data.current_page ?? 1);
                setHasMore(Boolean(data.next_page_url));
            })
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    };

    const loadMore = () => {
        setLoadingMore(true);
        fetcher({ ...lastParams.current, page: page + 1 })
            .then((data) => {
                setItems((prev) => [...prev, ...(data.data ?? [])]);
                setPage(data.current_page ?? page + 1);
                setHasMore(Boolean(data.next_page_url));
            })
            .catch((e) => setError(e.message))
            .finally(() => setLoadingMore(false));
    };

    return { items, loading, loadingMore, hasMore, error, load, loadMore };
}
