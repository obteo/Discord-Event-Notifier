import { definePterodactylExtension } from '@pterodactyl/sdk';
export default definePterodactylExtension({ setup({ screens }) {
  screens.register('discord-notifications', () => import('./preferences.js'));
} });
