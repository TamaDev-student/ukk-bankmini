import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
} from "react";

import api, { getCsrfCookie } from "../config/api";

const AuthContext = createContext(null);

//auth provider
export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);
    const [authError, setAuthError] = useState("");

    const refreshUser = useCallback(async () => {
        try {
            const response = await api.get("/web/user");

            const currentUser = response.data?.user ?? null;

            setUser(currentUser);
            setAuthError("");

            return currentUser;
        } catch (error) {
            const status = error.response?.status;

            if (status === 401 || status === 403) {
                setUser(null);
                setAuthError("");

                return null;
            }

            setAuthError("Sesi tidak dapat diperiksa. Pastikan server Laravel berjalan.");
            throw error;
        }
    }, []);


    //memulihkan status login ketika dibuka pertama kali
    useEffect(() => {
        let mounted = true;

        async function initializeAuth() {
            try {
                await refreshUser();
            } catch {
                //pesan error sudah dikelola oleh refreshUser().
            } finally {
                if (mounted) {
                    setLoading(false);
                }
            }

        }

        initializeAuth();
        return () => {
            mounted = false;
        };
    }, [refreshUser]);

    /**
     * Login menggunakan username dan password.
     *
     * Urutan:
     * 1. Meminta cookie CSRF.
     * 2. Mengirim kredensial ke Laravel.
     * 3. Menyimpan data pengguna ke state React.
     *
     * Tidak membuat atau menyimpan Bearer Token untuk web.
     */

    const login = useCallback(async (username, password) => {
        setAuthError(null);
        try {
            await getCsrfCookie();
            const response = await api.post('/web/login', {
                username,
                password,
            });

            const authenticatedUser = response.data?.user;
            if (!authenticatedUser) {
                throw new Error('Server tidak mengembalikan informasi pengguna.');
            }
            setUser(authenticatedUser);
            return authenticatedUser;
        } catch (error) {
            const status = error.response?.status;
            if (status === 401 || status === 403) {
                setUser(null);
            }
            setAuthError(error);
            throw error;
        }
    }, []);

    //logout make session web Laravel

    const logout = useCallback(async () => {
        try {
            await api.post("/web/logout");

            setUser(null);
            setAuthError("");
        } catch (error) {
            const status = error.response?.status;

            if (status === 401 || status === 403) {
                setUser(null);
                setAuthError("");

                return;
            }
        }
    }, []);

    const value = useMemo(
        () => ({
            user,
            loading,
            authError,
            login,
            logout,
            refreshUser,
            isAuthenticated: Boolean(user),
        }),
        [user, loading, authError, login, logout, refreshUser]
    );

    return (
        <AuthContext.Provider value={value}>
            {children}
        </AuthContext.Provider>
    );
}

//hook untuk mengakses autentikasi dari kompone react.
/* eslint-disable-next-line react-refresh/only-export-components */
export function useAuth() {
    const context = useContext(AuthContext);

    if (context === null) {
        throw new Error(
            "useAuth harus digunakan di dalam komponen AuthProvider."
        );
    }
    return context;
}
