# CyberPanel-Paymenter-Extension
Automatisez la gestion de votre hébergement web en connectant le système de facturation Paymenter à CyberPanel. Créez automatiquement les utilisateurs et les sites web, et gérez les sites (suspension, annulation, résiliation) directement via Paymenter.

# Prérequis
- Une installation Paymenter fonctionnelle
- Une installation CyberPanel fonctionnelle avec API Access activé sur un compte admin
  > Voir https://cyberpanel.net/KnowledgeBase/home/how-to-enable-api-access-in-cyberpanel/ 

# Installation
1. ```mkdir /var/www/paymenter/extensions/Servers/CyberPanel && cd /var/www/paymenter/extensions/Servers/CyberPanel```
2. ```wget https://raw.githubusercontent.com/TheOrion-OVH/CyberPanel-Paymenter-Extension/refs/heads/main/CyberPanel.php```
3. ```cd /var/www/paymenter```
4. ```php artisan optimize:clear```

Et voilà !

# Utilisation
1. Rendez vous sur votre Paymenter, puis dans la partie Admin, puis allez sur Servers
2. Faites Créer et suivez comme sur l'image
<img width="1450" height="771" alt="image" src="https://github.com/user-attachments/assets/d10fd347-8753-4fb5-a3dc-0c477b4fd3f9" />
4. Vous pouvez maintenant créer un produit et utiliser l'extension !

# Configurable Options
Vous pouvez aussi créer une configurable option permettant à l'utilisateur de choisir son nom de domaine !
1. Allez dans Config Options
2. Faites Créer

Remplissez comme sur l'image
<img width="1426" height="607" alt="image" src="https://github.com/user-attachments/assets/05a2d09f-5bb7-418e-b044-9ea751df3a3f" />

Pour toute question ou bug a signaler, dites le dans les Issues !
