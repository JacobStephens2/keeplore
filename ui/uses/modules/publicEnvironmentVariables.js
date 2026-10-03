// The API host comes from the server's API_ORIGIN (header.php meta tag), so
// staging calls api.staging.keeplore.app rather than production. API_BASE is
// the URL every script prefixes its endpoint paths with.
const apiOriginMeta = document.querySelector('meta[name="keeplore-api-origin"]');
const API_ORIGIN = apiOriginMeta ? apiOriginMeta.content : "api.keeplore.app";
const API_BASE = "https://" + API_ORIGIN;

export { API_ORIGIN, API_BASE };
