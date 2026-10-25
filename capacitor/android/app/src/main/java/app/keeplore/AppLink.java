package app.keeplore;

/**
 * An App Link loads only when its scheme is https and its host is keeplore.app.
 * The loaded URL is that https origin plus the link's path, query, and fragment.
 */
public final class AppLink {

    private AppLink() {
    }

    public static String urlToLoad(
            String scheme,
            String host,
            String encodedPath,
            String encodedQuery,
            String encodedFragment) {
        if (!"https".equalsIgnoreCase(scheme) || !"keeplore.app".equalsIgnoreCase(host)) {
            return null;
        }
        if (encodedPath != null && !encodedPath.isEmpty() && !encodedPath.startsWith("/")) {
            return null;
        }
        StringBuilder url = new StringBuilder("https://keeplore.app");
        if (encodedPath != null) {
            url.append(encodedPath);
        }
        if (encodedQuery != null) {
            url.append('?').append(encodedQuery);
        }
        if (encodedFragment != null) {
            url.append('#').append(encodedFragment);
        }
        return url.toString();
    }
}
