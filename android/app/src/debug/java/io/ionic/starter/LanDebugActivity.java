package io.ionic.starter;

import android.os.Bundle;
import android.webkit.WebSettings;

// Debug uses native HTTP for the LAN API; WebView mixed content stays blocked.
public class LanDebugActivity extends MainActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getBridge().getWebView().getSettings().setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
    }
}
