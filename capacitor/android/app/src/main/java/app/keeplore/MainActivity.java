package app.keeplore;

import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

/**
 * Opens an App Link in the WebView on a cold start and on a warm resume.
 *
 * Capacitor points the WebView at server.url and does not follow the tapped
 * path itself, so this activity does. An App Link loads only when its scheme
 * is https and its host is keeplore.app.
 */
public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // Cold start: app launched by tapping an App Link.
        navigateToAppLink(getIntent());
    }

    @Override
    public void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        // Warm resume: app already running (launchMode singleTask).
        setIntent(intent);
        navigateToAppLink(intent);
    }

    private void navigateToAppLink(Intent intent) {
        if (intent == null || !Intent.ACTION_VIEW.equals(intent.getAction())) {
            return;
        }
        Uri data = intent.getData();
        if (data == null) {
            return;
        }
        final String url = AppLink.urlToLoad(
                data.getScheme(),
                data.getHost(),
                data.getEncodedPath(),
                data.getEncodedQuery(),
                data.getEncodedFragment());
        if (url == null || getBridge() == null || getBridge().getWebView() == null) {
            return;
        }
        getBridge().getWebView().post(() -> getBridge().getWebView().loadUrl(url));
    }
}
