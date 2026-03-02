import React from 'react';
import { View, Text, TouchableOpacity, StyleSheet } from 'react-native';
import { MODES } from '../storage/settingsStore';

const labels = {
  [MODES.LOCAL]: 'Local',
  [MODES.HYBRID]: 'Hybrid',
  [MODES.REMOTE]: 'Remote',
};

export default function ModeSwitcher({ mode, onChange }) {
  return (
    <View style={styles.row}>
      {Object.values(MODES).map((m) => {
        const active = mode === m;
        return (
          <TouchableOpacity
            key={m}
            onPress={() => onChange(m)}
            style={[styles.button, active && styles.active]}
          >
            <Text style={[styles.text, active && styles.activeText]}>{labels[m]}</Text>
          </TouchableOpacity>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    gap: 8,
    paddingHorizontal: 12,
    paddingBottom: 8,
  },
  button: {
    paddingVertical: 6,
    paddingHorizontal: 10,
    borderRadius: 10,
    backgroundColor: '#1b1b2a',
  },
  active: {
    backgroundColor: '#4338ca',
  },
  text: {
    color: '#9ca3af',
    fontSize: 12,
    fontWeight: '600',
  },
  activeText: {
    color: '#fff',
  },
});
