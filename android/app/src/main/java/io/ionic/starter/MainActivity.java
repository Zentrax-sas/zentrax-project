package io.ionic.starter;

import com.getcapacitor.BridgeActivity;
import android.os.Bundle;

public class MainActivity extends BridgeActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        registerPlugin(WhatsAppExternoPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
