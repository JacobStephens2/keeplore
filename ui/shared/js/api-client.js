// Centralized API client for the artifact management tool
//
// Usage:
//   import ApiClient from '/shared/js/api-client.js';
//   const artifacts = await ApiClient.getArtifacts();
//
// The baseUrl is the API host the server configured (see
// publicEnvironmentVariables.js). Override it after import if needed:
// ApiClient.baseUrl = 'https://other-host';

import { API_ORIGIN } from '../../uses/modules/publicEnvironmentVariables.js';

const ApiClient = {
  baseUrl: `https://${API_ORIGIN}`,

  /**
   * Core request method. All other methods delegate here.
   *
   * @param {string} endpoint - The PHP endpoint path (e.g. 'artifacts.php')
   * @param {object} options  - Standard fetch options (method, body, etc.)
   * @returns {Promise<object>} Parsed JSON response body
   * @throws {Error} On rate-limit (429), auth failure, or any non-ok response
   */
  async request(endpoint, options = {}) {
    const url = `${this.baseUrl}/${endpoint}`;
    const defaults = {
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
    };
    const config = { ...defaults, ...options };

    // Merge headers so callers can add extra headers without losing Content-Type
    if (options.headers) {
      config.headers = { ...defaults.headers, ...options.headers };
    }

    const response = await fetch(url, config);

    if (response.status === 429) {
      throw new Error('Rate limit exceeded. Please try again later.');
    }

    if (!response.ok) {
      const error = await response.json().catch(() => ({}));
      throw new Error(error.message || `Request failed: ${response.status}`);
    }

    const data = await response.json();

    // The existing API returns { authenticated: false } on session expiry.
    // Redirect to the login page automatically so callers don't have to check.
    if (data.authenticated === false) {
      location.href = '/login.php';
      // Return a never-resolving promise so the caller doesn't continue.
      return new Promise(() => {});
    }

    return data;
  },

  // ---------------------------------------------------------------------------
  // GET methods
  // ---------------------------------------------------------------------------

  /**
   * Fetch a single artifact by its numeric ID.
   * Corresponds to GET /artifact.php?id=…
   */
  async getArtifact(id) {
    return this.request(`artifact.php?id=${encodeURIComponent(id)}`, {
      method: 'GET',
    });
  },

  /**
   * Fetch a list of artifacts with optional pagination and filters.
   *
   * @param {object} params
   * @param {number} [params.page]     - Offset-based page number
   * @param {number} [params.per_page] - Results per page (default 50 server-side)
   * @param {string} [params.cursor]   - Cursor for cursor-based pagination
   * @param {string} [params.userid]   - Scope results to a specific user
   *
   * Uses POST /artifacts.php because the existing API reads the body on POST.
   */
  async getArtifacts(params = {}) {
    return this.request('artifacts.php', {
      method: 'POST',
      body: JSON.stringify(params),
    });
  },

  /**
   * Fetch all artifact types.
   * Corresponds to GET /types.php
   */
  async getTypes() {
    return this.request('types.php', {
      method: 'GET',
    });
  },

  /**
   * Fetch use records. Optionally filtered by artifact ID.
   * Corresponds to GET /uses.php?artifact_id=…
   *
   * @param {number|null} artifactId - If provided, only return uses for this artifact.
   */
  async getUses(artifactId = null) {
    const qs = artifactId != null
      ? `?artifact_id=${encodeURIComponent(artifactId)}`
      : '';
    return this.request(`uses.php${qs}`, {
      method: 'GET',
    });
  },

  // ---------------------------------------------------------------------------
  // POST methods
  // ---------------------------------------------------------------------------

  /**
   * Create a new artifact.
   * Corresponds to POST /artifact.php
   *
   * @param {object} data - Item fields (Title required, plus optional fields)
   */
  async createArtifact(data) {
    return this.request('artifact.php', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  },

  /**
   * Record a new use of an artifact.
   * Corresponds to POST /uses.php
   *
   * @param {object} data - Must include artifact_id and use_date (YYYY-MM-DD).
   *                        Optional: note, notesTwo, user_id.
   */
  async createUse(data) {
    return this.request('uses.php', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  },

  /**
   * Search artifacts by title query, optionally scoped to a user.
   * Uses the same POST /artifacts.php endpoint with a query field.
   *
   * @param {string} query  - Search text
   * @param {string|null} userId - Optional user ID to scope the search
   */
  async searchArtifacts(query, userId = null) {
    const body = { query };
    if (userId != null) {
      body.userid = userId;
    }
    return this.request('artifacts.php', {
      method: 'POST',
      body: JSON.stringify(body),
    });
  },

  /**
   * Search users by name query, scoped to a user's context.
   * Corresponds to POST /users.php
   *
   * @param {string} query  - Search text
   * @param {string|null} userId - The requesting user's ID for context
   */
  async searchUsers(query, userId = null) {
    const body = { query };
    if (userId != null) {
      body.userid = userId;
    }
    return this.request('users.php', {
      method: 'POST',
      body: JSON.stringify(body),
    });
  },

  // ---------------------------------------------------------------------------
  // PUT methods
  // ---------------------------------------------------------------------------

  /**
   * Update an existing artifact.
   * Corresponds to PUT /artifact.php
   *
   * @param {number} id   - Item ID
   * @param {object} data - Fields to update
   */
  async updateArtifact(id, data) {
    return this.request('artifact.php', {
      method: 'PUT',
      body: JSON.stringify({ id, ...data }),
    });
  },

  // ---------------------------------------------------------------------------
  // DELETE methods
  // ---------------------------------------------------------------------------

  /**
   * Delete an artifact by ID.
   * Corresponds to DELETE /artifact.php?id=…
   *
   * @param {number} id - Item ID
   */
  async deleteArtifact(id) {
    return this.request(`artifact.php?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
    });
  },

  /**
   * Delete a use record by ID.
   * Corresponds to DELETE /uses.php?id=…
   *
   * @param {number} id - Use record ID
   */
  async deleteUse(id) {
    return this.request(`uses.php?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
    });
  },

  // ---------------------------------------------------------------------------
  // Utility: send use-notification email
  // ---------------------------------------------------------------------------

  /**
   * Trigger the "use notification" email for a given user.
   * Corresponds to GET /send_use_email.php?userID=…
   *
   * @param {number|string} userId
   */
  async sendUseEmail(userId) {
    return this.request(`send_use_email.php?userID=${encodeURIComponent(userId)}`, {
      method: 'GET',
    });
  },
};

export default ApiClient;
