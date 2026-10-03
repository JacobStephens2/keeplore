// The API host comes from the server's API_ORIGIN (header.php meta tag), so
// staging calls api.staging.keeplore.app rather than production.
const apiOriginMeta = document.querySelector('meta[name="keeplore-api-origin"]');
const API_ORIGIN = apiOriginMeta ? apiOriginMeta.content : "api.keeplore.app";

export { API_ORIGIN };
